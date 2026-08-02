<?php

namespace App\Domain\Ticketing\Models;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\QrCodeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The signed payload and rendered image for a ticket (ADR-03). Payload
 * format: `DTM1.<ticket_ulid>.<admits_total>.<exp_unix>.<key_id>.<sig_b64url>`.
 */
class QrCode extends Model
{
    /** @use HasFactory<QrCodeFactory> */
    use HasFactory, HasUlid;

    protected $table = 'qr_codes';

    protected $fillable = [
        'ticket_id',
        'payload_version',
        'payload',
        'payload_hash',
        'signature',
        'signing_key_id',
        'issued_at',
        'expires_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'image_media_id');
    }
}
