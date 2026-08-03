<?php

namespace App\Http\Resources;

use App\Domain\Reporting\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportExport
 */
class ReportExportResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ulid' => $this->ulid,
            'report_key' => $this->report_key,
            'format' => $this->format,
            'status' => $this->status,
            'row_count' => $this->row_count,
            'started_at' => $this->started_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'download_url' => $this->status === 'completed' ? $this->whenLoaded('media', fn () => $this->media?->path) : null,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
