<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('ticket_type.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:16', Rule::unique('ticket_types', 'code')],
            'name' => ['required', 'string', 'max:100'],
            'name_bn' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'base_price_paisa' => ['required', 'integer', 'min:0'],
            'additional_adult_price_paisa' => ['required', 'integer', 'min:0'],
            'additional_child_price_paisa' => ['required', 'integer', 'min:0'],
            // Null means this type has no student rate and a current
            // student pays `base_price_paisa` like everyone else. 0 is a
            // real price (a free student ticket), not "unset".
            'current_student_price_paisa' => ['nullable', 'integer', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'base_admits' => ['required', 'integer', 'min:1', 'max:20'],
            'max_admits' => ['required', 'integer', 'min:1', 'max:20', 'gte:base_admits'],
            // Null means this type has no free-infant rule at all.
            'child_free_under_age' => ['nullable', 'integer', 'min:1', 'max:18'],
            'allowed_participant_types' => ['nullable', 'array'],
            'allowed_participant_types.*' => ['string', Rule::in(['current_student', 'former_student', 'teacher', 'staff', 'guardian', 'guest', 'sponsor', 'other'])],
            'quantity_total' => ['nullable', 'integer', 'min:1'],
            'requires_approval' => ['nullable', 'boolean'],
            'includes_tshirt' => ['nullable', 'boolean'],
            'includes_meal' => ['nullable', 'boolean'],
            'sale_starts_at' => ['nullable', 'date'],
            'sale_ends_at' => ['nullable', 'date', 'after:sale_starts_at'],
            'is_active' => ['nullable', 'boolean'],
            'is_public' => ['nullable', 'boolean'],
            'badge_color' => ['nullable', 'string', 'max:7'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
