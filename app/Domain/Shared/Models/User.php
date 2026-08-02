<?php

namespace App\Domain\Shared\Models;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff account — Super Admin, Event Manager, or Volunteer.
 * Attendees are a separate identity domain; see {@see Attendee}.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, HasUlid, Notifiable, SoftDeletes;

    protected string $guard_name = 'web-admin';

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'status',
        'created_by_user_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by_user_id');
    }

    /**
     * @return HasOne<VolunteerProfile, $this>
     */
    public function volunteerProfile(): HasOne
    {
        return $this->hasOne(VolunteerProfile::class);
    }

    /**
     * @return HasMany<CheckIn, $this>
     */
    public function checkIns(): HasMany
    {
        return $this->hasMany(CheckIn::class, 'scanned_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
