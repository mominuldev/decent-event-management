<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class VerifyManualPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payment.verify_manual') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'verification_note' => ['required', 'string', 'max:255'],
        ];
    }
}
