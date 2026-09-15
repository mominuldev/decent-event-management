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
        'nid_number',
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
        'post_office',
        'upazila',
        'country',
        'blood_group',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    /**
     * The blood groups a form may offer, shared by every write path so the
     * public form, the counter form, the admin edit and the attendee's own
     * profile cannot drift into accepting different sets.
     *
     * A fixed list rather than free text because this is read in an
     * emergency: `O positive`, `o+ve` and `O+` are one answer to a person
     * and three unsearchable strings to whoever is filtering for a donor.
     *
     * @var list<string>
     */
    public const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    /**
     * The T-shirt sizes every write path accepts. One list here rather than
     * the same literal in four FormRequests, so a size added for the event
     * cannot be accepted at registration and refused on edit.
     *
     * @var list<string>
     */
    public const TSHIRT_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    /**
     * The classes a current student may be in — the school runs six to ten.
     *
     * Stored as a short language-neutral code, each frontend rendering
     * "Class Nine" / "নবম শ্রেণি" from it. `new_10` is "New Ten" — the
     * students just promoted into class ten, who the school counts
     * separately from the SSC candidates already in it. Rows that predate
     * this list may hold free text ("Class 9, Section B"); those are kept
     * as recorded and only ever replaced with a value from here.
     *
     * @var list<string>
     */
    public const CURRENT_CLASSES = ['6', '7', '8', '9', 'new_10', '10'];

    protected $hidden = [
        'auth_token_hash',
        'password',
        'remember_token',
    ];

    /**
     * The name to address this attendee by in a Bangla message.
     *
     * The public form has required `full_name_bn` since 2026-08-16, but rows
     * created before that — and by an admin or an import, which still accept
     * neither as required — may not have it, and greeting somebody by an
     * empty string is worse than greeting them in Latin script.
     */
    public function banglaName(): string
    {
        return (string) ($this->full_name_bn ?: $this->full_name);
    }

    /**
     * Whether this attendee can sign in with a password.
     *
     * False is an ordinary state, not a broken one: every attendee created
     * before 2026-08-22, and every one an admin adds or an import loads, has
     * none. Those sign in with a one-time SMS code and set a password
     * afterwards.
     */
    public function hasPassword(): bool
    {
        return $this->password !== null && $this->password !== '';
    }

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'tshirt_required' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
            'auth_token_expires_at' => 'datetime',
            'password_set_at' => 'datetime',
            // Hashes on assignment, so no write path can store a plaintext
            // password by forgetting to call Hash::make().
            'password' => 'hashed',
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
     * Confirm — or withdraw confirmation of — this attendee's identity.
     *
     * All three columns move together and none of them is `$fillable`: who
     * vouched for an attendee is an authority fact, and an authority column
     * must not be settable by any array that happens to carry the key (same
     * discipline as `qr_codes.image_media_id` and `Refund`'s approval
     * columns). Before this existed, `is_verified` was validated by
     * `UpdateAttendeeRequest` and then silently dropped by mass assignment,
     * so the admin console's Verified switch saved successfully and changed
     * nothing at all.
     *
     * Withdrawing clears the attribution rather than leaving it behind: a row
     * reading "not verified, verified by Rahim on 3 May" is a lie, and this
     * table is read during reconciliation.
     */
    public function applyVerification(bool $verified, ?User $by = null): void
    {
        if ($verified === (bool) $this->is_verified) {
            return;
        }

        $this->forceFill($verified
            ? [
                'is_verified' => true,
                'verified_by_user_id' => $by?->getKey(),
                'verified_at' => now(),
            ]
            : [
                'is_verified' => false,
                'verified_by_user_id' => null,
                'verified_at' => null,
            ]);

        $this->save();
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
