<?php

namespace App\Domain\Content\Actions;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * The CMS media library's upload path — also the endpoint D9 flagged as
 * missing, which is what leaves manual payment proof unusable today.
 *
 * Follows the upload rules in CLAUDE.md / docs/06 §6.5 exactly: the file's
 * type is decided by its magic bytes (never its extension or the client's
 * `Content-Type`), every accepted image is fully re-encoded — which strips
 * EXIF/GPS and any payload smuggled into the container — and the result is
 * stored under a randomised name.
 *
 * CMS media is `is_public` by design: sponsor logos and gallery images are
 * served straight off the CDN to an anonymous public site, so a signed
 * short-TTL URL would defeat caching for no benefit. Private collections
 * (payment proofs, ticket PDFs) must not be routed through here.
 */
class UploadContentMedia
{
    /** Public disk — see the class note on why CMS media is not signed-URL only. */
    private const string DISK = 'public';

    /**
     * Raster formats GD can fully re-encode. SVG is deliberately absent: it
     * is a script-bearing XML document, not a raster image, and there is no
     * re-encode step that makes it safe to serve from our own origin.
     *
     * @var array<string, string>
     */
    private const array ACCEPTED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    /**
     * Where CMS uploads may land. Keeps the `collection` column a closed set
     * rather than a client-supplied free string.
     *
     * @var list<string>
     */
    public const array COLLECTIONS = ['content', 'page_og', 'sponsor_logo', 'speaker_photo', 'gallery'];

    public function execute(
        UploadedFile $file,
        string $collection,
        User $uploader,
        ?string $ip = null,
        ?string $requestId = null,
    ): MediaFile {
        if (! in_array($collection, self::COLLECTIONS, true)) {
            throw new InvalidArgumentException("Unsupported media collection [{$collection}].");
        }

        $mime = $this->detectMimeType($file);

        if (! array_key_exists($mime, self::ACCEPTED)) {
            throw new InvalidArgumentException('Only JPEG, PNG and WebP images can be uploaded.');
        }

        [$binary, $width, $height] = $this->reencode($file, $mime);

        $extension = self::ACCEPTED[$mime];
        $path = 'content/'.Str::lower((string) Str::ulid()).'.'.$extension;

        Storage::disk(self::DISK)->put($path, $binary, 'public');

        return DB::transaction(function () use (
            $binary, $collection, $extension, $file, $height, $ip, $mime, $path, $requestId, $uploader, $width
        ): MediaFile {
            $media = MediaFile::create([
                'collection' => $collection,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 185, ''),
                'mime_type' => $mime,
                'extension' => $extension,
                'size_bytes' => strlen($binary),
                'checksum_sha256' => hash('sha256', $binary),
                'width' => $width,
                'height' => $height,
                'is_public' => true,
                // The stored bytes are GD's output, not the uploader's: for
                // the three raster formats accepted here, the re-encode *is*
                // the sanitising step this column tracks. Wiring a real AV
                // scanner for the private collections is separate work.
                'scan_status' => 'clean',
                'scanned_at' => now(),
                'uploaded_by_type' => 'user',
                'uploaded_by_id' => $uploader->id,
            ]);

            ActivityLog::create([
                'log_name' => 'content',
                'event' => 'media_uploaded',
                'description' => "Uploaded {$collection} media {$media->ulid}",
                'causer_type' => $uploader->getMorphClass(),
                'causer_id' => $uploader->id,
                'subject_type' => $media->getMorphClass(),
                'subject_id' => $media->id,
                'properties' => [
                    'collection' => $collection,
                    'mime_type' => $mime,
                    'size_bytes' => $media->size_bytes,
                    'dimensions' => "{$width}x{$height}",
                ],
                'ip_address' => $ip,
                'request_id' => $requestId,
            ]);

            return $media;
        });
    }

    /**
     * Reads the type out of the file's own bytes. `getClientMimeType()` and
     * the filename extension are both attacker-controlled and are never
     * consulted.
     */
    private function detectMimeType(UploadedFile $file): string
    {
        $path = $file->getRealPath();

        if ($path === false) {
            throw new InvalidArgumentException('The uploaded file could not be read.');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException('fileinfo is unavailable; uploads cannot be validated.');
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? '' : $mime;
    }

    /**
     * Decodes and re-emits the image through GD. Nothing of the original
     * container survives — no EXIF, no GPS, no trailing appended data.
     *
     * @return array{0: string, 1: int, 2: int} re-encoded bytes, width, height
     */
    private function reencode(UploadedFile $file, string $mime): array
    {
        $path = $file->getRealPath();
        $contents = $path === false ? false : file_get_contents($path);

        if ($contents === false) {
            throw new InvalidArgumentException('The uploaded file could not be read.');
        }

        $image = @imagecreatefromstring($contents);

        if (! $image instanceof GdImage) {
            throw new InvalidArgumentException('The uploaded file is not a readable image.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        // PNG and WebP can carry an alpha channel; without these two calls
        // GD flattens transparency to black on the way out.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();

        $ok = match ($mime) {
            'image/jpeg' => imagejpeg($image, null, 85),
            'image/png' => imagepng($image, null, 6),
            'image/webp' => imagewebp($image, null, 85),
            default => false,
        };

        $binary = (string) ob_get_clean();
        imagedestroy($image);

        if (! $ok || $binary === '') {
            throw new RuntimeException('The image could not be re-encoded.');
        }

        return [$binary, $width, $height];
    }
}
