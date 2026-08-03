<?php

namespace App\Http\Requests\Scanner;

use Illuminate\Foundation\Http\FormRequest;

class StoreScanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth handled by middleware
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gate_id' => ['required', 'string', 'exists:gates,id'],
            'scans' => ['required', 'array', 'min:1', 'max:100'],
            'scans.*.client_scan_uuid' => ['required', 'uuid'],
            'scans.*.raw_payload' => ['required', 'string', 'max:500'],
            'scans.*.party_size' => ['required', 'integer', 'min:1', 'max:20'],
            'scans.*.scanned_at' => ['required', 'date'],
            'scans.*.latitude' => ['nullable', 'numeric'],
            'scans.*.longitude' => ['nullable', 'numeric'],
        ];
    }
}
