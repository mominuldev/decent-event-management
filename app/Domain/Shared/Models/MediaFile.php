<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Support\HasUlid;
use Database\Factories\MediaFileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

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
}
