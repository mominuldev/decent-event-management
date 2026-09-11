<?php

namespace App\Http\Requests\Admin;

use App\Domain\Ticketing\Actions\ResendTicketNotification;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResendTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('notification.resend');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Required, with no default. SMS costs real money against a
            // prepaid balance, so "which channels" is a decision the
            // operator makes every time rather than one they inherit from
            // whatever the form last had selected.
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string', Rule::in(ResendTicketNotification::CHANNELS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'channels.required' => 'Choose at least one channel to resend on.',
            'channels.*.in' => 'Tickets can be resent by email or SMS only.',
        ];
    }
}
