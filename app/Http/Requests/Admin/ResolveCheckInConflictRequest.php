<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ResolveCheckInConflictRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('checkin.resolve_conflict') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
