<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Support\HasImmutableCreatedAt;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The audit trail. Every privileged, financial, or destructive action,
 * with actor, target, before/after diff, and network context.
 */
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory, HasImmutableCreatedAt, HasUlid;

    public $timestamps = false;

    protected $fillable = [
        'log_name',
        'event',
        'description',
        'causer_type',
        'causer_id',
        'impersonator_user_id',
        'subject_type',
        'subject_id',
        'properties',
        'ip_address',
        'user_agent',
        'request_id',
        'severity',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'properties' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }
}
