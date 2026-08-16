<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Services\GenerateMediaThumbnail;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * One table for every uploaded and generated file — profile photos,
 * payment screenshots, ticket PDFs, QR images.
 */
class MediaFile extends Model
{
    /** @use HasFactory<MediaFileFactory> */
    use HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'collection',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'extension',
        'size_bytes',
        'checksum_sha256',
        'width',
        'height',
        'is_public',
        'scan_status',
        'uploaded_by_type',
        'uploaded_by_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'scanned_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * The small derived rendition, when one exists. Deliberately outside
     * `$fillable` — nothing should mass-assign a variant link; it is written
     * by {@see GenerateMediaThumbnail} through
     * `forceFill()`, the same discipline as `qr_codes.image_media_id`.
     *
     * @return BelongsTo<self, $this>
     */
    public function thumbnail(): BelongsTo
    {
        return $this->belongsTo(self::class, 'thumbnail_media_id');
    }

    /**
     * The cheapest rendition that still shows the image — the thumbnail when
     * one has been generated, otherwise the file itself.
     *
     * Falling back rather than returning null matters for two real cases: a
     * photo uploaded before thumbnails existed and not yet backfilled, and an
     * original already smaller than the thumbnail budget (for which no
     * derivative is generated at all — see GenerateMediaThumbnail).
     */
    public function smallest(): self
    {
        return $this->thumbnail ?? $this;
    }

    public function passedVirusScan(): bool
    {
        return $this->scan_status === 'clean';
    }

    /**
     * Direct URL for files explicitly marked public — CMS logos, gallery
     * images, page hero art. Returns null for everything else: private files
     * (payment proofs, ticket PDFs) are only ever served through short-TTL
     * signed URLs, never a guessable path (docs/06 §6.5).
     */
    public function publicUrl(): ?string
    {
        if (! $this->is_public) {
            return null;
        }

        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * The short-TTL signed URL for a private file (docs/06 §6.4 — 15
     * minutes). Callers are responsible for the policy check *before*
     * minting this — the signature is the only check the serving route
     * itself makes.
     */
    public function temporarySignedUrl(int $minutes = 15): string
    {
        return URL::temporarySignedRoute('api.v1.media.show', now()->addMinutes($minutes), ['mediaFile' => $this->ulid]);
    }
}
