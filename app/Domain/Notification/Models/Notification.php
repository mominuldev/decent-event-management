<?php

namespace App\Domain\Notification\Models;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Support\HasStateMachine;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The transactional outbox (ADR-07). Written inside the business
 * transaction, drained by workers. Not `Illuminate\Notifications`.
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory, HasStateMachine, HasUlid;

    protected $table = 'notifications';

    public const array TRANSITIONS = [
        'queued' => ['sending', 'cancelled'],
        'sending' => ['sent', 'queued', 'failed'],
        'sent' => ['delivered', 'bounced'],
        'delivered' => ['read'],
        'failed' => [],
        'bounced' => [],
        'read' => [],
        'cancelled' => [],
    ];

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'attendee_id',
        'template_key',
        'channel',
        'locale',
        'recipient',
        'subject',
        'body_rendered',
        'payload',
        'attachment_media_id',
        'status',
        'priority',
        'max_attempts',
        'scheduled_for',
        'provider',
        'dedupe_key',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Attendee, $this>
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'attachment_media_id');
    }

    /**
     * @return HasMany<NotificationEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(NotificationEvent::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function notifiable(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'notifiable_type', 'notifiable_id');
    }
}
