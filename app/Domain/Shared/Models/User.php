<?php

namespace App\Domain\Shared\Models;

use App\Domain\CheckIn\Models\CheckIn;
use App\Domain\CheckIn\Models\VolunteerProfile;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Support\HasUlid;
use App\Mail\StaffPasswordResetMail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
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

    /**
     * Overrides the framework's default, which would send Laravel's own
     * ResetPassword notification. Ours goes to the admin console rather than
     * a `password.reset` web route this application does not have, and is
     * sent inline — see StaffPasswordResetMail for why it stays outside the
     * notification outbox.
     *
     * The URL is built from config('app.url'), never from the request: an
     * origin a caller supplied would let somebody mail a real staff member a
     * real token pointing at a site they control.
     */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = rtrim((string) config('app.url'), '/')
            .'/reset-password?token='.urlencode((string) $token)
            .'&email='.urlencode((string) $this->email);

        $expires = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        Mail::to($this->email)->send(new StaffPasswordResetMail($this, $url, $expires));
    }
}
