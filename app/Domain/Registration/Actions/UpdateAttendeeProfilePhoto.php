<?php

namespace App\Domain\Registration\Actions;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\MediaFile;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Self-service account photo change — the attendee-authenticated sibling of
 * UploadAttendeePhoto, which is registration-scoped, unauthenticated, and
 * only open while that registration is still `pending_payment`. This one has
 * no such window: an attendee's own account photo can change any time.
 *
 * Same re-encode discipline and storage half as UploadAttendeePhoto (magic
 * bytes decide the type, the image is fully re-encoded and downscaled, the
 * stored name is random, the disk is private) — duplicated rather than
 * shared, matching this codebase's existing convention of one self-contained
 * Action per upload path (see UploadContentMedia's near-identical copy).
 */
class UpdateAttendeeProfilePhoto
{
    private const string DISK = 'local';

    private const string COLLECTION = 'profile_photo';

    /** Mirrors UploadAttendeePhoto's own budget — a badge-sized photo. */
    private const int MAX_DIMENSION = 1024;

    /** @var array<string, string> */
    private const array ACCEPTED = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function execute(
        Attendee $attendee,
        UploadedFile $file,
        ?string $ip = null,
        ?string $requestId = null,
    ): MediaFile {
        $mime = $this->detectMimeType($file);

        if (! array_key_exists($mime, self::ACCEPTED)) {
            throw new InvalidArgumentException('Only JPEG, PNG and WebP images can be uploaded.');
        }

        [$binary, $width, $height] = $this->reencode($file, $mime);

        $extension = self::ACCEPTED[$mime];
        $path = 'profile-photos/'.Str::lower((string) Str::ulid()).'.'.$extension;

        Storage::disk(self::DISK)->put($path, $binary);

        return DB::transaction(function () use (
            $attendee, $binary, $extension, $file, $height, $ip, $mime, $path, $requestId, $width
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
                // Same reasoning as UploadAttendeePhoto: for these three
                // raster formats the GD round-trip *is* the sanitising step.
                'scan_status' => 'clean',
                'scanned_at' => now(),
                'uploaded_by_type' => 'attendee',
                'uploaded_by_id' => $attendee->id,
            ]);

            // Queried by the FK directly rather than through the `profilePhoto`
            // relation: a caller may already have lazy-loaded (and cached)
            // that relation before this Action ever ran — e.g. the same
            // long-lived Attendee instance across a queued job, or Octane —
            // and an Eloquent relation cache doesn't invalidate itself just
            // because forceFill() below changes the FK it was resolved from.
            $previousMediaId = $attendee->profile_photo_media_id;
            $previous = $previousMediaId !== null ? MediaFile::find($previousMediaId) : null;

            $attendee->forceFill(['profile_photo_media_id' => $media->id])->save();

            // The old file is orphaned the moment the FK moves off it — soft
            // delete so a stale signed URL someone still has open resolves to
            // nothing rather than staying servable for its full 15 minutes.
            $previous?->delete();

            // Audit lives in the Action, not the controller (D8) — a photo
            // replaced by an admin tool or a console command has to leave
            // the same trail as one replaced through HTTP.
            ActivityLog::create([
                'log_name' => 'attendee',
                'event' => 'attendee_photo_changed',
                'description' => "Attendee {$attendee->ulid} changed their profile photo to {$media->ulid}",
                'causer_type' => $attendee->getMorphClass(),
                'causer_id' => $attendee->id,
                'subject_type' => $attendee->getMorphClass(),
                'subject_id' => $attendee->id,
                'properties' => [
                    'media_ulid' => $media->ulid,
                    'replaced_media_ulid' => $previous?->ulid,
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
     * Nothing of the original container survives — no EXIF, no GPS, no
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

        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);
        imagedestroy($image);

        return $resized;
    }
}
