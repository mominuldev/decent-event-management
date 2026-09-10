<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RefundPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payment.refund') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'amount_paisa' => ['nullable', 'integer', 'min:1'],
            'type' => ['required', 'string', Rule::in(['full', 'partial'])],

            // Only meaningful for a gateway with no refund API (PayStation).
            // Left optional here rather than conditionally required: which
            // gateway a payment used — and therefore whether the pair is
            // needed — is a fact about the Payment, not the request body,
            // so RefundPayment enforces it and answers with the distinct
            // `out_of_band_refund_acknowledgement_required` code.
            'acknowledged_out_of_band' => ['sometimes', 'boolean'],
            'gateway_refund_reference' => ['nullable', 'string', 'max:120'],
        ];
    }
}
