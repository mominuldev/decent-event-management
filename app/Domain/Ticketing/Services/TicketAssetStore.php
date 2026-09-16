<?php

namespace App\Domain\Ticketing\Services;

use App\Domain\Shared\Models\MediaFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Writes a rendered ticket asset — QR PNG, PDF, share card — to the
 * private disk and records it as a `media_files` row. One writer for all
 * three so the row shape (private, system-uploaded, marked clean because
 * nothing here came from a user) cannot drift between them.
 */
class TicketAssetStore
{
    public const string DISK = 'local';

    public function put(string $binary, string $collection, string $mimeType, string $extension, string $originalName): MediaFile
    {
        $path = "{$collection}/".Str::lower((string) Str::ulid()).".{$extension}";

        Storage::disk(self::DISK)->put($path, $binary);

        $size = getimagesizefromstring($binary);

        return MediaFile::create([
            'collection' => $collection,
            'disk' => self::DISK,
            'path' => $path,
            'original_name' => $originalName,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => strlen($binary),
            'checksum_sha256' => hash('sha256', $binary),
            'width' => $size !== false ? $size[0] : null,
            'height' => $size !== false ? $size[1] : null,
            'is_public' => false,
            'scan_status' => 'clean',
            'scanned_at' => now(),
            'uploaded_by_type' => 'system',
            'uploaded_by_id' => null,
        ]);
    }
}
