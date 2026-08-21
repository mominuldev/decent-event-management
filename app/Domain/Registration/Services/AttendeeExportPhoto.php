<?php

namespace App\Domain\Registration\Services;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\MediaFile;
use GdImage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns an attendee's profile photo into a small image both export writers can
 * embed, plus the neutral placeholder that stands in when there isn't one.
 *
 * Shared by the spreadsheet and PDF writers rather than duplicated into each,
 * because these decisions have to come out the same way in both or the two
 * exports disagree about the same attendee:
 *
 *  1. **Which rendition.** The 128px thumbnail when it is big enough for the
 *     size being asked for, the original otherwise — see {@see self::source()}.
 *  2. **Always re-encoded, never passed through.** The stored thumbnail is
 *     WebP, which neither Excel nor mpdf renders dependably — Excel in
 *     particular shows an empty box rather than an error. Photographs come
 *     back as JPEG and the placeholder as PNG, chosen per kind of image
 *     rather than per format of file; see {@see self::toJpeg()} for why that
 *     is worth 75MB on a large spreadsheet.
 *  3. **A missing or unreadable file is not a failure.** A photo whose blob
 *     has gone (a restored database pointed at a fresh disk, a half-finished
 *     upload) must fall back to the placeholder and let the other 4,999 rows
 *     through, not abort the operator's export. It is logged, so it stays
 *     visible rather than becoming indistinguishable from "never had one".
 */
class AttendeeExportPhoto
{
    /**
     * Rendered placeholders, keyed by the size and shape asked for.
     *
     * Worth memoising because it is the *common* case, not the rare one: on a
     * roster where most attendees predate the photo upload, this is one GD
     * render reused thousands of times instead of thousands of identical ones.
     *
     * @var array<string, string>
     */
    private array $placeholders = [];

    /**
     * Encoded bytes for the attendee's photo (JPEG — see
     * {@see self::toJpeg()}), or null when there is no usable image.
     *
     * With no `$aspectRatio` the image keeps its own proportions and is
     * downscaled so its longest edge is at most `$maxPx` — what the
     * spreadsheet wants, where every photo sits in an identical square cell
     * and a stretched face would be worse than a letterboxed one.
     *
     * With an `$aspectRatio` (width ÷ height) it is centre-cropped to that
     * shape first, then scaled — what the PDF directory wants, where the
     * photos are printed as a column of identically-sized portraits and a
     * ragged edge is the thing that reads as broken. Cropping rather than
     * stretching is the whole point: these are photographs of people.
     */
    public function render(Attendee $attendee, int $maxPx, ?float $aspectRatio = null): ?string
    {
        $photo = $attendee->profilePhoto;

        if ($photo === null) {
            return null;
        }

        $media = $this->source($photo, $maxPx);

        try {
            if (! Storage::disk($media->disk)->exists($media->path)) {
                Log::warning('Attendee export: profile photo missing from disk.', [
                    'attendee_id' => $attendee->id,
                    'media_file_id' => $media->id,
                    'disk' => $media->disk,
                    'path' => $media->path,
                ]);

                return null;
            }

            $binary = Storage::disk($media->disk)->get($media->path);

            if ($binary === null || $binary === '') {
                return null;
            }

            $image = @imagecreatefromstring($binary);

            if ($image === false) {
                Log::warning('Attendee export: profile photo could not be decoded.', [
                    'attendee_id' => $attendee->id,
                    'media_file_id' => $media->id,
                    'mime_type' => $media->mime_type,
                ]);

                return null;
            }

            $scaled = $aspectRatio !== null
                ? $this->cover($image, $maxPx, $aspectRatio)
                : $this->fit($image, $maxPx);

            try {
                return $this->toJpeg($scaled);
            } finally {
                if ($scaled !== $image) {
                    imagedestroy($scaled);
                }
                imagedestroy($image);
            }
        } catch (Throwable $e) {
            Log::warning('Attendee export: profile photo skipped.', [
                'attendee_id' => $attendee->id,
                'media_file_id' => $media->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The stand-in for an attendee with no usable photo: a neutral card with
     * a generic head-and-shoulders silhouette, in the same shape and size a
     * real photo would have occupied.
     *
     * Drawn with GD rather than shipped as an asset file so it always matches
     * whatever size and aspect the caller asks for, needs no `storage:link`,
     * and cannot go missing from a deploy the way a checked-in image can.
     *
     * Filling the slot matters more than it looks: in a printed directory a
     * genuinely empty cell reads as a layout fault, and it collapses the row
     * height so the grid stops lining up. A drawn silhouette is also honest —
     * it says "no photograph on file" rather than implying the export broke.
     */
    public function placeholder(int $maxPx, ?float $aspectRatio = null): string
    {
        $ratio = $aspectRatio ?? 1.0;

        $longest = max(1, $maxPx);

        [$width, $height] = $ratio >= 1.0
            ? [$longest, max(1, (int) round($longest / $ratio))]
            : [max(1, (int) round($longest * $ratio)), $longest];

        $key = "{$width}x{$height}";

        if (isset($this->placeholders[$key])) {
            return $this->placeholders[$key];
        }

        $canvas = imagecreatetruecolor($width, $height);

        // Deliberately low-contrast. On a roster where most records predate
        // photo upload this is the majority of the page, and a dark silhouette
        // repeated fifty times reads as a wall of blobs that competes with the
        // text people are actually there to read.
        $background = (int) imagecolorallocate($canvas, 0xF2, 0xF4, 0xF7);
        $figure = (int) imagecolorallocate($canvas, 0xCB, 0xD1, 0xDA);

        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, $background);

        // Proportions of the figure are expressed as fractions of the box, so
        // the silhouette looks the same at 96px in a spreadsheet cell and at
        // 200px in a printed portrait.
        $headRadius = (int) round(min($width, $height) * 0.19);
        $headCentreY = (int) round($height * 0.36);
        $centreX = intdiv($width, 2);

        imagefilledellipse($canvas, $centreX, $headCentreY, $headRadius * 2, $headRadius * 2, $figure);

        // Shoulders: an ellipse wider than the box, clipped by the box edges,
        // so it reads as a torso rising out of the bottom rather than a blob.
        $shoulderWidth = (int) round($width * 0.72);
        $shoulderHeight = (int) round($height * 0.62);
        imagefilledellipse($canvas, $centreX, (int) round($height * 0.92), $shoulderWidth, $shoulderHeight, $figure);

        try {
            return $this->placeholders[$key] = (string) $this->toPng($canvas);
        } finally {
            imagedestroy($canvas);
        }
    }

    /**
     * Which stored rendition to read.
     *
     * The 128px thumbnail is the cheap answer and the right one for a 96px
     * spreadsheet cell — it saves pulling a ~231KB badge photo off disk per
     * row. It is the wrong one for the printed directory, which asks for a
     * larger image than the thumbnail holds; upscaling 128px into a 20mm
     * printed portrait is visibly soft. So the thumbnail is used only when it
     * is genuinely big enough, and the original otherwise.
     */
    private function source(MediaFile $photo, int $maxPx): MediaFile
    {
        $small = $photo->smallest();

        if ($small->is($photo)) {
            return $photo;
        }

        return ($small->width ?? 0) >= $maxPx && ($small->height ?? 0) >= $maxPx
            ? $small
            : $photo;
    }

    /**
     * Centre-crop to `$aspectRatio` (width ÷ height), then scale so the
     * longest edge is `$maxPx`.
     *
     * The crop is taken from the middle horizontally but from the *top* third
     * vertically: a portrait photograph puts the face above centre, and a
     * centred vertical crop on a full-length shot reliably cuts the head off.
     */
    private function cover(GdImage $image, int $maxPx, float $aspectRatio): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $cropWidth = min($width, (int) round($height * $aspectRatio));
        $cropHeight = min($height, (int) round($cropWidth / $aspectRatio));

        $targetHeight = max(1, min($maxPx, $cropHeight));
        $targetWidth = max(1, (int) round($targetHeight * $aspectRatio));

        if ($aspectRatio > 1.0) {
            $targetWidth = max(1, min($maxPx, $cropWidth));
            $targetHeight = max(1, (int) round($targetWidth / $aspectRatio));
        }

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        $resampled = imagecopyresampled(
            $canvas,
            $image,
            0,
            0,
            (int) round(($width - $cropWidth) / 2),
            (int) round(($height - $cropHeight) / 3),
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight,
        );

        if (! $resampled) {
            imagedestroy($canvas);

            return $image;
        }

        return $canvas;
    }

    /**
     * Downscale so the longest edge is `$maxPx`. Never upscales — an image
     * already inside the budget is returned untouched, which is the normal
     * case now that thumbnails are 128px and this asks for 96px or less.
     *
     * May return the handle it was given; the caller compares identity before
     * destroying, so a resized copy is freed and the original is not
     * double-freed.
     */
    private function fit(GdImage $image, int $maxPx): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $maxPx) {
            return $image;
        }

        $scale = $maxPx / $longest;

        $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        return $resized === false ? $image : $resized;
    }

    /**
     * Encode a photograph. JPEG, not PNG, and the difference is not marginal:
     * PNG is lossless and a photograph has no large flat areas for it to
     * exploit, so a 5,000-row spreadsheet of 96px PNG portraits measured
     * **90MB** against ~15MB for the same images as JPEG. A 90MB workbook is
     * not a slow download, it is one nobody can email.
     *
     * PNG is still right for the placeholder, which is two flat colours — the
     * codecs are chosen per kind of image, not per format of file.
     *
     * The canvas is flattened onto white first because JPEG has no alpha: a
     * profile photo uploaded as a transparent PNG would otherwise composite
     * against black and come out as a silhouette on a dark square.
     */
    private function toJpeg(GdImage $image): ?string
    {
        $width = imagesx($image);
        $height = imagesy($image);

        $flattened = imagecreatetruecolor($width, $height);
        imagefilledrectangle($flattened, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($flattened, 255, 255, 255));
        imagecopy($flattened, $image, 0, 0, 0, 0, $width, $height);

        try {
            ob_start();
            $ok = imagejpeg($flattened, null, 82);
            $jpeg = ob_get_clean();

            return ($ok && is_string($jpeg) && $jpeg !== '') ? $jpeg : null;
        } finally {
            imagedestroy($flattened);
        }
    }

    private function toPng(GdImage $image): ?string
    {
        // Keep transparency rather than flattening it to black, which is what
        // an un-flagged palette conversion does to a PNG with an alpha channel.
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagepng($image, null, 6);
        $png = ob_get_clean();

        return ($ok && is_string($png) && $png !== '') ? $png : null;
    }
}
