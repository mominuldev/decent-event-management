<?php

namespace App\Domain\CheckIn\Models;

use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\CheckInDeviceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An enrolled scanner device. Enrolment binds a Sanctum token to hardware
 * so a leaked token cannot be used from an arbitrary machine.
 */
class CheckInDevice extends Model
{
    /** @use HasFactory<CheckInDeviceFactory> */
    use HasFactory, HasUlid;

    protected $fillable = [
        'device_code',
        'device_name',
        'device_fingerprint',
        'platform',
        'app_version',
        'os_version',
        'assigned_volunteer_profile_id',
        'sanctum_token_id',
        'status',
        'enrolled_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<VolunteerProfile, $this>
     */
    public function volunteerProfile(): BelongsTo
    {
        return $this->belongsTo(VolunteerProfile::class, 'assigned_volunteer_profile_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by_user_id');
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class, 'device_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
