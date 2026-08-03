<?php

namespace App\Domain\CheckIn\Models;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasImmutableCreatedAt;
use App\Domain\Shared\Support\HasUlid;
use App\Domain\Ticketing\Models\Ticket;
use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Every scan attempt, admitted and rejected (ADR-05). This is both the
 * attendance record and the gate dispute log — see docs/04 §4.7.
 */
class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
    use HasFactory, HasImmutableCreatedAt, HasUlid;

    public const array TERMINAL_RESULTS = [
        'admitted',
        'duplicate',
        'revoked',
        'unpaid',
        'invalid_signature',
        'invalid_format',
        'expired',
        'over_capacity',
        'wrong_gate',
        'wrong_session',
        'manual_override',
    ];

    public $timestamps = false;

    protected $fillable = [
        'client_scan_uuid',
        'ticket_id',
        'registration_id',
        'attendee_id',
        'event_session_id',
        'gate_id',
        'device_id',
        'scanned_by_user_id',
        'result',
        'rejection_detail',
        'admitted_count',
        'admitted_guest_ids',
        'raw_payload',
        'signature_valid',
        'scan_mode',
        'is_manual_override',
        'override_by_user_id',
        'override_reason',
        'conflict_flag',
        'conflict_resolved_at',
        'conflict_resolved_by_user_id',
        'scanned_at',
        'synced_at',
        'device_clock_skew_ms',
        'latitude',
        'longitude',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'admitted_guest_ids' => 'array',
            'signature_valid' => 'boolean',
            'is_manual_override' => 'boolean',
            'conflict_flag' => 'boolean',
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
            'conflict_resolved_at' => 'datetime',
            'created_at' => 'datetime',
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
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * @return BelongsTo<Attendee, $this>
     */
    public function attendee(): BelongsTo
    {
        return $this->belongsTo(Attendee::class);
    }

    /**
     * @return BelongsTo<EventSession, $this>
     */
    public function eventSession(): BelongsTo
    {
        return $this->belongsTo(EventSession::class);
    }

    /**
     * @return BelongsTo<Gate, $this>
     */
    public function gate(): BelongsTo
    {
        return $this->belongsTo(Gate::class);
    }

    /**
     * @return BelongsTo<CheckInDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(CheckInDevice::class, 'device_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function scannedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function overrideBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'override_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function conflictResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conflict_resolved_by_user_id');
    }

    public function isRejection(): bool
    {
        return $this->result !== 'admitted' && $this->result !== 'manual_override';
    }
}
