<?php

namespace App\Http\Requests\Admin;

use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Notification\Support\SmsSenderId;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Support\EventSettingCatalogue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') ?? false;
    }

    /**
     * Validate against the setting's own declared `type`, not just presence.
     * Without this a `datetime` row accepts `"banana"` at write time and then
     * throws from `EventSetting::typedValue()` on every subsequent read —
     * a bad save takes the whole settings screen down rather than failing
     * where the mistake was made.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // A secret is cleared by saving it empty, so `required` is wrong for
        // it — `present` keeps the field mandatory while allowing the empty
        // string that means "remove this credential".
        if ($this->setting()?->isSecret()) {
            return ['value' => ['present', 'nullable', 'string', 'max:500']];
        }

        return [
            'value' => match ($this->setting()?->type) {
                'int' => ['required', 'integer'],
                'money' => ['required', 'integer', 'min:0'],
                'bool' => ['required', 'boolean'],
                'datetime' => ['required', 'date'],
                'json' => ['required'],
                default => ['required', 'string'],
            },
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // A sender ID that does not match the account's mode is accepted
            // by the gateway and then fails at the carrier, where it is far
            // harder to diagnose. This is the last point where someone is
            // looking at the field.
            if ($this->setting()?->key === 'sms.sender_id') {
                $problem = SmsSenderId::problemWith(
                    (string) $this->input('value'),
                    app(SmsGatewayConfig::class)->maskingEnabled(),
                );

                if ($problem !== null) {
                    $validator->errors()->add('value', $problem);
                }
            }

            if ($this->setting()?->type !== 'json') {
                return;
            }

            $value = $this->input('value');

            if (is_string($value) && json_decode($value) === null && mb_strtolower(trim($value)) !== 'null') {
                $validator->errors()->add('value', 'The value must be valid JSON.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'value.integer' => 'This setting only accepts a whole number.',
            'value.boolean' => 'This setting only accepts true or false.',
            'value.date' => 'This setting only accepts a valid date and time.',
            'value.string' => 'This setting only accepts text.',
        ];
    }

    /**
     * The row being edited — the stored one, or an unsaved row built from
     * `config/event_settings.php` for a setting this environment has never
     * saved. Resolving through the catalogue rather than the table is what
     * makes the rules above right for a first save: without it a `bool` or a
     * `datetime` with no row yet would fall through to the `default` arm and
     * be validated as plain text, and a credential would miss `isSecret()`
     * and so be stored unencrypted.
     *
     * Null only when the key is neither stored nor defined, which is the
     * controller's 404.
     */
    public function setting(): ?EventSetting
    {
        return once(fn () => EventSettingCatalogue::resolve((string) $this->route('key')));
    }
}
