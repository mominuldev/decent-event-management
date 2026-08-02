<?php

namespace App\Domain\CheckIn\Models;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\VolunteerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VolunteerProfile extends Model
{
    /** @use HasFactory<VolunteerProfileFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'user_id',
        'volunteer_code',
        'pin_hash',
        'pin_set_at',
        'team',
        'shift_starts_at',
        'shift_ends_at',
        'is_active',
    ];

    protected $hidden = [
        'pin_hash',
    ];

    protected function casts(): array
    {
        return [
            'pin_set_at' => 'datetime',
            'shift_starts_at' => 'datetime',
            'shift_ends_at' => 'datetime',
            'is_active' => 'boolean',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    /**
     * @return HasMany<VolunteerGateAssignment, $this>
     */
    public function gateAssignments(): HasMany
    {
        return $this->hasMany(VolunteerGateAssignment::class);
    }

    /**
     * @return HasMany<CheckInDevice, $this>
     */
    public function devices(): HasMany
    {
        return $this->hasMany(CheckInDevice::class, 'assigned_volunteer_profile_id');
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
