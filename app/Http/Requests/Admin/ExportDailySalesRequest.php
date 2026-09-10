<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * The daily sales report's filters plus a format.
 *
 * Extends the screen's own request rather than restating its rules: the date
 * window, the batch and ticket-type filters and the 366-day ceiling are the
 * same contract, and a copy is how an export comes to accept a window the
 * report itself refuses (or the reverse).
 */
class ExportDailySalesRequest extends DailySalesReportRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'format' => ['required', 'string', Rule::in(['csv', 'xlsx', 'pdf'])],
        ];
    }
}
