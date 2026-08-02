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
}
