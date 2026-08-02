<?php

namespace App\Domain\Shared\Models;

use App\Domain\Shared\Support\HasImmutableCreatedAt;
use Database\Factories\IdempotencyKeyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic replay protection for unsafe operations, so a double-tapped
 * "Pay" button or a retried webhook cannot produce two effects.
 */
class IdempotencyKey extends Model
{
    /** @use HasFactory<IdempotencyKeyFactory> */
    use HasFactory, HasImmutableCreatedAt;

    public $timestamps = false;

    protected $primaryKey = 'id';

    protected $fillable = [
        'key',
        'scope',
        'request_hash',
        'response_status',
        'response_body',
        'locked_at',
        'completed_at',
        'expires_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function isInFlight(): bool
    {
        return $this->locked_at !== null && $this->completed_at === null;
    }
}
