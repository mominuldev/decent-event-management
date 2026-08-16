<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\MediaFile;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Derives a small rendition of a stored image and links the original to it.
 *
 * Shared rather than copied into each upload Action — unlike the re-encode
 * step those Actions deliberately duplicate, this one has a caller that is not
 * an upload path at all (`media:backfill-thumbnails`), and a third copy living
 * in a console command is how the two upload paths would drift apart.
 *
 * Idempotent by construction: a media file that already carries a thumbnail is
 * returned untouched, so a re-run of the backfill — or a retried job — never
 * produces a second derivative.
 */
class GenerateMediaThumbnail
{
    /**
     * Longest side of the derived image. The admin attendee list renders a
     * 36px avatar and the detail dialog a 72px one; 128 covers both at 2x on
     * a retina display with nothing left over. The point of this class is that
     * a ~1024px badge photo (hundreds of KB, sized for the A5 ticket PDF) is
     * never what fills a 36px circle twenty rows at a time.
     */
    public const int MAX_DIMENSION = 128;

    public const string COLLECTION = 'thumbnail';

    /**
     * Source formats GD can decode here. Everything else — an SVG, a PDF, a
     * ticket QR already stored as a MediaFile — is left alone.
     *
     * @var array<string, string>
     */
    private const array DECODABLE = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Output is WebP whatever went in. A photograph re-encoded as PNG at this
     * size measured ~20 KB against ~5 KB for the same pixels as WebP, and a
     * list renders twenty of them at once — the format is most of the win
     * here, not just the smaller dimensions. WebP also keeps the alpha
     * channel that switching to JPEG would flatten, so a logo survives the
     * same path as a portrait.
     */
    private const string OUTPUT_MIME = 'image/webp';

    /**
     * Returns the thumbnail, or null when none is warranted or possible:
     * a non-image, an original already within the budget (the caller falls
     * back to the original — see MediaFile::smallest()), or a row whose file
     * has gone missing from its disk.
     */
    public function execute(MediaFile $media): ?MediaFile
    {
        if ($media->thumbnail_media_id !== null) {
            return $media->thumbnail;
        }

        if (! array_key_exists((string) $media->mime_type, self::DECODABLE)) {
            return null;
        }

        $disk = Storage::disk($media->disk);

        if (! $disk->exists($media->path)) {
            return null;
        }

        $source = @imagecreatefromstring((string) $disk->get($media->path));

        if (! $source instanceof GdImage) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);

        // Already small enough that a derivative would be a second copy of
        // the same bytes. Left unlinked on purpose: `smallest()` falls back
        // to the original, and the backfill re-examines such a row cheaply
        // rather than being told a lie by a self-referencing pointer.
        if (max($width, $height) <= self::MAX_DIMENSION) {
            imagedestroy($source);

            return null;
        }

        [$binary, $thumbWidth, $thumbHeight, $mime] = $this->resize($source, (string) $media->mime_type);

        $extension = self::DECODABLE[$mime];
        $path = 'thumbnails/'.Str::lower((string) Str::ulid()).'.'.$extension;

        // Written before the row exists, matching the upload Actions: a
        // failure here leaves an unreferenced file rather than a row
        // pointing at bytes that were never stored.
        $disk->put($path, $binary);

        $thumbnail = MediaFile::create([
            'collection' => self::COLLECTION,
            'disk' => $media->disk,
            'path' => $path,
            'original_name' => $media->original_name,
            'mime_type' => $mime,
            'extension' => $extension,
            'size_bytes' => strlen($binary),
            'checksum_sha256' => hash('sha256', $binary),
            'width' => $thumbWidth,
            'height' => $thumbHeight,
            // Inherited, never assumed: a thumbnail of a private photograph
            // must not become publicly fetchable just because it is small.
            'is_public' => $media->is_public,
            // Derived by GD from bytes that already passed the parent's own
            // re-encode; there is no attacker-supplied container left.
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'uploaded_by_type' => $media->uploaded_by_type,
            'uploaded_by_id' => $media->uploaded_by_id,
        ]);

        $media->forceFill(['thumbnail_media_id' => $thumbnail->id])->save();
        $media->setRelation('thumbnail', $thumbnail);

        return $thumbnail;
    }

    /**
     * @return array{0: string, 1: int, 2: int, 3: string} bytes, width, height, mime
     */
    private function resize(GdImage $source, string $sourceMime): array
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = self::MAX_DIMENSION / max($width, $height);

        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $resized instanceof GdImage) {
            imagedestroy($source);

            throw new RuntimeException('The thumbnail canvas could not be allocated.');
        }

        // Without these, GD flattens PNG/WebP transparency to opaque black.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($source);

        // GD can be built without WebP. Rather than fail every row on such a
        // box, fall back to the source format — the row records its own
        // mime_type, so a mixed-format estate stays self-describing.
        $mime = function_exists('imagewebp') ? self::OUTPUT_MIME : $sourceMime;

        ob_start();

        $ok = match ($mime) {
            'image/webp' => imagewebp($resized, null, 80),
            'image/jpeg' => imagejpeg($resized, null, 80),
            'image/png' => imagepng($resized, null, 6),
            default => false,
        };

        $binary = (string) ob_get_clean();
        imagedestroy($resized);

        if (! $ok || $binary === '') {
            throw new RuntimeException('The thumbnail could not be encoded.');
        }

        return [$binary, $targetWidth, $targetHeight, $mime];
    }
}
