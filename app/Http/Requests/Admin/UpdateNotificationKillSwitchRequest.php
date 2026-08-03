<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationKillSwitchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('notification.send_broadcast') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'channel' => ['required', 'string', 'in:email,sms,whatsapp'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
