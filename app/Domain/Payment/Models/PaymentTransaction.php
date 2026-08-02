<?php

namespace App\Domain\Payment\Models;

use App\Domain\Shared\Support\HasImmutableCreatedAt;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only log of every gateway interaction. Never updated, never
 * deleted — reconciliation and dispute resolution read this table.
 */
class PaymentTransaction extends Model
{
    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory, HasImmutableCreatedAt, HasUlid;

    public $timestamps = false;

    protected $fillable = [
        'payment_id',
        'type',
        'direction',
        'gateway',
        'status',
        'amount_paisa',
        'currency',
        'gateway_reference',
        'gateway_transaction_id',
        'gateway_status_code',
        'gateway_message',
        'request_payload',
        'response_payload',
        'signature_valid',
        'ip_address',
        'latency_ms',
        'idempotency_key',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'response_payload' => 'array',
            'signature_valid' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Payment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
