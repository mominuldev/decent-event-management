<?php

namespace App\Http\Requests\Admin;

use App\Domain\Registration\Support\AttendeeListFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportAttendeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('attendee.export');
    }

    /**
     * The filter rules mirror what the list endpoint accepts, validated
     * against AttendeeListFilters::PARTICIPANT_TYPES so the export can never
     * reject a participant type that really exists in the table.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['xlsx', 'pdf'])],
            'search' => ['nullable', 'string', 'max:150'],
            'participant_type' => ['nullable', 'string', Rule::in(AttendeeListFilters::PARTICIPANT_TYPES)],
            'ssc_batch_year' => ['nullable', 'integer', 'min:1971', 'max:'.date('Y')],
            // Shape only, not value: an unknown field or direction falls back
            // to the default inside ListSort, which is also what the list
            // endpoint does — so the two cannot answer in different orders.
            'sort' => ['nullable', 'string', 'max:40'],
            'direction' => ['nullable', 'string', 'max:4'],
        ];
    }

    /**
     * Includes the sort, so the exported file lands in the same order as the
     * screen the operator pressed Export on.
     *
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        return $this->safe()->only(['search', 'participant_type', 'ssc_batch_year', 'sort', 'direction']);
    }
}
