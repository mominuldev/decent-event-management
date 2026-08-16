<?php

namespace App\Domain\Registration\Actions;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Services\GenerateMediaThumbnail;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Badge photo for the alumnus registering — the one file the public ticket
 * flow collects.
 *
 * Deliberately *not* routed through Content's UploadContentMedia: that one
 * writes the public CDN disk with `is_public = true`, which is right for a
 * sponsor logo and wrong for a photograph of a private individual. The
 * validation half is the same discipline (magic bytes decide the type, the
 * image is fully re-encoded, the stored name is random) but the storage
 * half is private-disk plus short-TTL signed URLs, matching ticket PDFs.
 *
 * Scoping the upload to a registration rather than exposing a bare public
 * upload endpoint is what keeps this from being an open file drop: a caller
 * must already hold an unguessable registration ULID, and each registration
 * accepts a photo only during its own checkout window.
 */
class UploadAttendeePhoto
{
    private const string DISK = 'local';

    private const string COLLECTION = 'profile_photo';

    /**
     * A badge photo is printed at a few centimetres and embedded in an A5
     * PDF; anything past this is bytes nobody sees.
     */
    private const int MAX_DIMENSION = 1024;

    /** @var array<string, string> */
    private const array ACCEPTED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function __construct(private readonly GenerateMediaThumbnail $thumbnailer) {}

    public function execute(
        Registration $registration,
        UploadedFile $file,
        ?string $ip = null,
        ?string $requestId = null,
    ): MediaFile {
        // The photo belongs to the checkout. Once a registration has left
        // pending_payment its ticket may already be rendered, and a photo
        // swapped in afterwards would silently disagree with the PDF in the
        // attendee's inbox.
        if ($registration->status !== 'pending_payment') {
            throw new InvalidArgumentException('This registration is no longer accepting a photo.');
        }

        /** @var Attendee|null $attendee */
        $attendee = $registration->attendee;

        if ($attendee === null) {
            throw new RuntimeException('The registration has no attendee to attach a photo to.');
        }

        $mime = $this->detectMimeType($file);

        if (! array_key_exists($mime, self::ACCEPTED)) {
            throw new InvalidArgumentException('Only JPEG, PNG and WebP images can be uploaded.');
        }

        [$binary, $width, $height] = $this->reencode($file, $mime);

        $extension = self::ACCEPTED[$mime];
        $path = 'profile-photos/'.Str::lower((string) Str::ulid()).'.'.$extension;

        Storage::disk(self::DISK)->put($path, $binary);

        return DB::transaction(function () use (
            $attendee, $binary, $extension, $file, $height, $ip, $mime, $path, $registration, $requestId, $width
        ): MediaFile {
            $media = MediaFile::create([
                'collection' => self::COLLECTION,
                'disk' => self::DISK,
                'path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 185, ''),
                'mime_type' => $mime,
                'extension' => $extension,
                'size_bytes' => strlen($binary),
                'checksum_sha256' => hash('sha256', $binary),
                'width' => $width,
                'height' => $height,
                'is_public' => false,
                // Same reasoning as UploadContentMedia: for these three
                // raster formats the GD round-trip *is* the sanitising step,
                // since nothing of the uploaded container survives it.
                'scan_status' => 'clean',
                'scanned_at' => now(),
                'uploaded_by_type' => 'attendee',
                'uploaded_by_id' => $attendee->id,
            ]);

            // Derived here rather than on a queue: the admin attendee list is
            // the first thing to read a new photo back, and a list that has to
            // wait for a worker would fall back to the full-size original —
            // the very download this exists to avoid. GD has already proven it
            // can decode and encode these exact bytes a few lines above, so a
            // failure now is a bug worth surfacing, not something to swallow.
            $this->thumbnailer->execute($media);

            $previous = $attendee->profile_photo_media_id;

            $attendee->forceFill(['profile_photo_media_id' => $media->id])->save();

            // Audit lives in the Action, not the controller (D8) — a photo
            // replaced by an admin tool or a console command has to leave
            // the same trail as one replaced through HTTP.
            ActivityLog::create([
                'log_name' => 'registration',
                'event' => 'attendee_photo_uploaded',
                'description' => "Uploaded profile photo {$media->ulid} for attendee {$attendee->ulid}",
                'causer_type' => $attendee->getMorphClass(),
                'causer_id' => $attendee->id,
                'subject_type' => $attendee->getMorphClass(),
                'subject_id' => $attendee->id,
                'properties' => [
                    'registration_ulid' => $registration->ulid,
                    'media_ulid' => $media->ulid,
                    'replaced_media_id' => $previous,
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
     * Type comes from the file's own bytes. `getClientMimeType()` and the
     * filename extension are attacker-controlled and never consulted.
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
     * Decodes, downscales to fit MAX_DIMENSION, and re-emits through GD.
     * Nothing of the original container survives — no EXIF, no GPS (which
     * for a photograph of a person is the point, not a side effect), no
     * appended trailing data.
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

        $image = $this->downscale($image);

        $width = imagesx($image);
        $height = imagesy($image);

        // PNG and WebP can carry alpha; without these two calls GD flattens
        // transparency to black on the way out.
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

    /** Fits the image inside MAX_DIMENSION, preserving aspect ratio. */
    private function downscale(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= self::MAX_DIMENSION) {
            return $image;
        }

        $scale = self::MAX_DIMENSION / $longest;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);

        if (! $resized instanceof GdImage) {
            throw new RuntimeException('The image could not be resized.');
        }

        // Preserve transparency through the resample rather than filling
        // the new canvas with opaque black.
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }
}
