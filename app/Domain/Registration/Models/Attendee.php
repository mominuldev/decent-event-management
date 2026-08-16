<?php

namespace App\Domain\Registration\Models;

use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\HasUlid;
use Database\Factories\AttendeeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthUserBase;
use Laravel\Sanctum\HasApiTokens;

/**
 * The person. Separate identity domain from staff {@see User} — see docs/02 §2.1.
 * Deduplicated on normalised mobile number (ADR-08).
 */
class Attendee extends AuthUserBase
{
    /** @use HasFactory<AttendeeFactory> */
    use HasApiTokens, HasFactory, HasUlid, SoftDeletes;

    protected $fillable = [
        'full_name',
        'full_name_bn',
        'father_name',
        'mobile',
        'whatsapp_number',
        'email',
        'gender',
        'date_of_birth',
        'occupation',
        'designation',
        'organization',
        'participant_type',
        'ssc_batch_year',
        'current_class',
        'profile_photo_media_id',
        'tshirt_required',
        'tshirt_size',
        'address_district',
        'current_address',
        'country',
        'blood_group',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    protected $hidden = [
        'auth_token_hash',
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'tshirt_required' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'auth_token_expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MediaFile, $this>
     */
    public function profilePhoto(): BelongsTo
    {
        return $this->belongsTo(MediaFile::class, 'profile_photo_media_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    /**
     * @return BelongsTo<self, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_attendee_id');
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function isEligibleForBatchYear(): bool
    {
        return in_array($this->participant_type, ['current_student', 'former_student'], true);
    }
}
