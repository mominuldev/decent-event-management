<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CollectCashPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payment.collect_cash') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required and compared against `amount_due_paisa` in the
            // Action, not defaulted to it here: the operator has to state
            // what they actually took, so a mistyped figure is refused with
            // both numbers named rather than silently recorded as correct.
            'amount_received_paisa' => ['required', 'integer', 'min:0'],
            // The paper receipt book number, where one is used. Optional —
            // not every desk runs one, and blocking a completed sale on a
            // field nobody has is the wrong trade at a queue.
            'receipt_reference' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }
}
