<?php

namespace App\Domain\Shared\Models;

use Carbon\Carbon;
use Database\Factories\EventSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Typed key-value configuration so the Super Admin can change dates,
 * cutoffs, and toggles without a deployment.
 */
class EventSetting extends Model
{
    /** @use HasFactory<EventSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'group',
        'value',
        'type',
        'is_encrypted',
        'is_public',
        'label',
        'description',
        'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
            'is_public' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Casts the stored string value to its declared type.
     */
    public function typedValue(): mixed
    {
        return match ($this->type) {
            'int' => (int) $this->value,
            'bool' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode((string) $this->value, true),
            'datetime' => $this->value !== null ? Carbon::parse($this->value) : null,
            'money' => (int) $this->value,
            default => $this->value,
        };
    }

    /**
     * The inverse of {@see typedValue()} — narrows a typed input back to the
     * one canonical string form this row stores, so `true`, `"1"` and `1` all
     * land as `'1'` and a datetime is always persisted in the app timezone
     * regardless of the offset the client sent it in.
     */
    public function castForStorage(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($this->type) {
            'int', 'money' => (string) (int) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'json' => is_string($value) ? $value : (string) json_encode($value),
            // Carbon::parse keeps whatever offset the caller sent, so this must
            // shift into the app timezone explicitly — otherwise a cutoff saved
            // from a +06:00 browser stores six hours later than it means.
            'datetime' => Carbon::parse((string) $value)
                ->setTimezone((string) config('app.timezone'))
                ->toDateTimeString(),
            default => (string) $value,
        };
    }
}
