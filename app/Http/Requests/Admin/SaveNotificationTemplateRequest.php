<?php

namespace App\Http\Requests\Admin;

use App\Domain\Notification\Models\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveNotificationTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('notification.manage_templates') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->route('template') === null;

        return [
            // Identity is create-only. Editing it on an existing row would
            // silently retarget every notification that uses the template.
            'key' => [Rule::requiredIf($creating), 'prohibited_if:_editing,1', 'string', 'max:64', 'regex:/^[a-z0-9_.]+$/'],
            'channel' => [Rule::requiredIf($creating), 'string', Rule::in(['email', 'sms', 'whatsapp'])],
            'locale' => [Rule::requiredIf($creating), 'string', Rule::in(['en', 'bn'])],
            'version' => ['nullable', 'integer', 'min:1'],

            // Only email has a subject; sending one for an SMS would store a
            // field nothing reads and imply it appears somewhere.
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'is_active' => ['nullable', 'boolean'],
            'variables' => ['nullable', 'array'],
            'variables.*' => ['string', 'max:64'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->route('template') !== null) {
                return;
            }

            // The table's own unique index is (key, channel, locale,
            // version). Hitting it raw is a 500 with a SQL error in the log;
            // this is the same refusal as a field-level 422 naming what
            // already exists.
            $exists = NotificationTemplate::query()
                ->where('key', $this->input('key'))
                ->where('channel', $this->input('channel'))
                ->where('locale', $this->input('locale'))
                ->where('version', (int) ($this->input('version') ?? 1))
                ->exists();

            if ($exists) {
                $validator->errors()->add('key', 'A template already exists for that key, channel and language. Edit it instead.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'A template key may only contain lowercase letters, numbers, dots and underscores.',
        ];
    }
}
