<?php

namespace App\Http\Requests\Admin;

use App\Domain\Reporting\Support\DailySalesReport;
use App\Domain\Ticketing\Models\TicketType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class DailySalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Enforced in the controller alongside every other report, so the
        // permission a report key needs stays readable in one place.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            // The oldest SSC batch a centennial school could plausibly have,
            // and next year — a typo'd 202 or 20255 should be a field error
            // rather than a query that quietly matches nothing.
            'ssc_batch_year' => ['nullable', 'integer', 'min:1900', 'max:'.(date('Y') + 1)],
            'ticket_type_ulid' => ['nullable', 'string', 'size:26', 'exists:ticket_types,ulid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to.after_or_equal' => 'The end date cannot be before the start date.',
            'ticket_type_ulid.exists' => 'That ticket type does not exist.',
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $from = $this->input('from');
            $to = $this->input('to');

            if (! is_string($from) || ! is_string($to) || $validator->errors()->isNotEmpty()) {
                return;
            }

            // A day-count ceiling rather than a row-count one, because the
            // response carries a row for every day in the window including
            // the empty ones. Refused naming both figures, following the
            // attendee export's `export_too_large`: a limit an operator
            // cannot see is one they cannot work around.
            $days = CarbonImmutable::parse($from)->startOfDay()
                ->diffInDays(CarbonImmutable::parse($to)->startOfDay()) + 1;

            if ($days > DailySalesReport::MAX_DAYS) {
                $validator->errors()->add(
                    'to',
                    "That range covers {$days} days; this report answers at most ".DailySalesReport::MAX_DAYS.'.'
                );
            }
        });
    }

    /**
     * The filter set {@see DailySalesReport::build()} takes.
     *
     * The ULID is resolved to the internal id here so the report's `where`
     * runs on the indexed foreign key, while no auto-increment primary key
     * ever reaches or leaves the API.
     *
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        $ulid = $this->validated('ticket_type_ulid');

        return [
            'from' => $this->validated('from'),
            'to' => $this->validated('to'),
            'ssc_batch_year' => $this->validated('ssc_batch_year'),
            'ticket_type_ulid' => $ulid,
            'ticket_type_id' => is_string($ulid)
                ? TicketType::query()->where('ulid', $ulid)->value('id')
                : null,
        ];
    }
}
