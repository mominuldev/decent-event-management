<?php

namespace App\Domain\Registration\Models;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Notification\Models\Notification;
use App\Domain\Payment\Models\Payment;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasStateMachine;
use App\Domain\Shared\Support\HasUlid;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Database\Factories\RegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One application to attend, made by one lead attendee. Lifecycle state
 * machine: docs/04 §4.7. Any transition not drawn there is invalid.
 */
class Registration extends Model
{
    /** @use HasFactory<RegistrationFactory> */
    use HasFactory, HasStateMachine, HasUlid, SoftDeletes;

    public const array TRANSITIONS = [
        'draft' => ['pending_payment', 'expired'],
        'pending_payment' => ['paid', 'expired', 'cancelled'],
        'paid' => ['confirmed'],
        'confirmed' => ['cancelled', 'refunded'],
        'cancelled' => ['refunded'],
        'expired' => ['pending_payment'],
        'refunded' => [],
    ];

    protected $fillable = [
        'registration_number',
        'attendee_id',
        'ticket_type_id',
        'event_session_id',
        'participation_type',
        'adults_count',
        'children_count',
        'status',
        'subtotal_paisa',
        'discount_paisa',
        'total_paisa',
        'currency',
        'discount_code',
        'comments',
        'special_notes',
        'source',
        'created_by_user_id',
        'ip_address',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'locked_at' => 'datetime',
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
     * @return BelongsTo<TicketType, $this>
     */
    public function ticketType(): BelongsTo
    {
        return $this->belongsTo(TicketType::class);
    }

    /**
     * @return BelongsTo<EventSession, $this>
     */
    public function eventSession(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @return HasMany<RegistrationGuest, $this>
     */
    public function guests(): HasMany
    {
        return $this->hasMany(RegistrationGuest::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Notification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'notifiable_id')->where('notifiable_type', 'registration');
    }
}
