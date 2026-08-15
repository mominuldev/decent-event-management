<?php

namespace App\Http\Requests\Admin;

use App\Domain\Shared\Models\EventSetting;
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
     * The row being edited, or null when the key does not exist — in which
     * case the controller's `firstOrFail()` is what answers, with a 404.
     */
    public function setting(): ?EventSetting
    {
        return once(fn () => EventSetting::where('key', (string) $this->route('key'))->first());
    }
}
