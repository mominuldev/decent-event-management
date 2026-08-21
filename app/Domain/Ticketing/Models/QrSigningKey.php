<?php

namespace App\Domain\Ticketing\Models;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasStateMachine;
use App\Domain\Shared\Support\HasUlid;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One QR signing key's lifecycle (docs/06 §6.5). Holds the public half
 * only — see the migration for why the private half never lands here.
 *
 * @property int $id
 * @property string $ulid
 * @property string $key_id
 * @property string $public_key
 * @property string $status
 * @property Carbon|null $published_at
 * @property Carbon|null $activated_at
 * @property Carbon|null $retired_at
 */
class QrSigningKey extends Model
{
    use HasStateMachine;
    use HasUlid;

    public const string PENDING = 'pending';

    public const string ACTIVE = 'active';

    public const string RETIRED = 'retired';

    /**
     * A key can be abandoned before it ever signs anything (pending →
     * retired) — that is the escape hatch when a rotation is called off
     * partway. There is no route back to pending: a key that has signed
     * real tickets must keep verifying them forever.
     */
    public const array TRANSITIONS = [
        self::PENDING => [self::ACTIVE, self::RETIRED],
        self::ACTIVE => [self::RETIRED],
        self::RETIRED => [],
    ];

    protected $fillable = [
        'key_id',
        'public_key',
        'status',
        'published_at',
        'activated_at',
        'retired_at',
        'published_by_user_id',
        'activated_by_user_id',
        'retired_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'activated_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    /**
     * When devices could first have learned this key.
     *
     * `published_at` is set by the publish action, but coalescing to the row
     * timestamp keeps a single non-null answer for the sync gate — a device
     * that synced before the row existed cannot have the key either way.
     */
    public function publishedAt(): CarbonInterface
    {
        return $this->published_at ?? $this->created_at ?? now();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    /**
     * @param  Builder<QrSigningKey>  $query
     * @return Builder<QrSigningKey>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::ACTIVE);
    }

    /**
     * Every key a scanner might still need in order to verify a QR that is
     * legitimately in circulation — which is all of them, including retired
     * ones, since a ticket signed months ago must keep working.
     *
     * @param  Builder<QrSigningKey>  $query
     * @return Builder<QrSigningKey>
     */
    public function scopePublishable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PENDING, self::ACTIVE, self::RETIRED]);
    }
}
