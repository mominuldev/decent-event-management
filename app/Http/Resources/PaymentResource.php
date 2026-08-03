<?php

namespace App\Http\Resources;

use App\Domain\Payment\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'payment_number' => $this->payment_number,
            'method' => $this->method,
            'channel' => $this->channel,
            'status' => $this->status,
            'amount_due_paisa' => $this->amount_due_paisa,
            'amount_paid_paisa' => $this->amount_paid_paisa,
            'fee_paisa' => $this->fee_paisa,
            'net_paisa' => $this->net_paisa,
            'refunded_paisa' => $this->refunded_paisa,
            'currency' => $this->currency,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'payer_msisdn' => $this->payer_msisdn,
            'manual_trx_id' => $this->manual_trx_id,
            'reconciliation_status' => $this->reconciliation_status,
            'initiated_at' => $this->initiated_at?->toISOString(),
            'paid_at' => $this->paid_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'verified_at' => $this->verified_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'verified_by_name' => $this->whenLoaded('verifiedBy', fn () => $this->verifiedBy?->name),
        ];
    }
}
