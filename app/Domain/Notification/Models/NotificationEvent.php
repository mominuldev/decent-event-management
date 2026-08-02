<?php

namespace App\Domain\Notification\Models;

use App\Domain\Shared\Support\HasImmutableCreatedAt;
use Database\Factories\NotificationEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delivery receipts and status transitions from providers — SMS DLRs,
 * WhatsApp status webhooks, email bounces and opens.
 */
class NotificationEvent extends Model
{
    /** @use HasFactory<NotificationEventFactory> */
    use HasFactory, HasImmutableCreatedAt;

    public $timestamps = false;

    protected $fillable = [
        'notification_id',
        'event',
        'provider_status',
        'detail',
        'raw_payload',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Notification, $this>
     */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
