<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The permission depends on the requested format (report.export_pdf/excel/csv),
        // so it's enforced in ReportController::export() after validation resolves `format`.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['required', 'string', Rule::in(['pdf', 'xlsx', 'csv'])],
            'filters' => ['nullable', 'array'],
        ];
    }
}
