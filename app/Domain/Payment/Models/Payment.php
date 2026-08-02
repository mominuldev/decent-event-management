<?php

namespace App\Domain\Payment\Models;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasStateMachine;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The money intent for a registration. `status` reaches `succeeded` only
 * through server-to-server verification — never a browser callback alone.
 * See docs/01 §1.7 and docs/04 §4.7.
 */
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasStateMachine, HasUlid;

    public const array TRANSITIONS = [
        'pending' => ['initiated', 'awaiting_verification'],
        'initiated' => ['processing', 'expired', 'cancelled'],
        'processing' => ['succeeded', 'failed'],
        'awaiting_verification' => ['succeeded', 'rejected'],
        'succeeded' => ['partially_refunded', 'refunded'],
        'partially_refunded' => ['refunded'],
        'failed' => [],
        'rejected' => [],
        'refunded' => [],
    ];

    protected $fillable = [
        'payment_number',
        'registration_id',
        'attendee_id',
        'method',
        'channel',
        'status',
        'amount_due_paisa',
        'amount_paid_paisa',
        'fee_paisa',
        'net_paisa',
        'refunded_paisa',
        'currency',
        'gateway_reference',
        'gateway_transaction_id',
        'payer_msisdn',
        'manual_trx_id',
        'manual_proof_media_id',
        'manual_sender_note',
        'verified_by_user_id',
        'verified_at',
        'verification_note',
        'rejection_reason',
        'initiated_at',
        'paid_at',
        'expires_at',
        'failed_at',
        'idempotency_key',
        'reconciled_at',
        'reconciliation_status',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'initiated_at' => 'datetime',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'failed_at' => 'datetime',
            'reconciled_at' => 'datetime',
        ];
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
     * @return BelongsTo<MediaFile, $this>
     */
    public function manualProof(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'manual_proof_media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * @return HasMany<PaymentTransaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    /**
     * @return HasMany<Refund, $this>
     */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function isManual(): bool
    {
        return $this->channel === 'manual';
    }
}
