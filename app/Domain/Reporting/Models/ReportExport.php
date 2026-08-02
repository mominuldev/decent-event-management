<?php

namespace App\Domain\Reporting\Models;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Async export job record. A 20,000-row Excel export must not run inside
 * an HTTP request.
 */
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'report_key',
        'format',
        'filters',
        'status',
        'row_count',
        'media_id',
        'requested_by_user_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
