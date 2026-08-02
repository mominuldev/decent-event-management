<?php

namespace App\Domain\Payment\Models;

use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\RefundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    /** @use HasFactory<RefundFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'refund_number',
        'payment_id',
        'registration_id',
        'amount_paisa',
        'currency',
        'reason',
        'type',
        'method',
        'status',
        'requested_by_user_id',
        'recipient_msisdn',
        'voided_ticket_ids',
    ];

    protected function casts(): array
    {
        return [
            'voided_ticket_ids' => 'array',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }
}
