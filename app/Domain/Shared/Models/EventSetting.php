<?php

namespace App\Domain\Shared\Models;

use App\Http\Resources\EventSettingResource;
use Carbon\Carbon;
use Database\Factories\EventSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Typed key-value configuration so the Super Admin can change dates,
 * cutoffs, and toggles without a deployment.
 *
 * **Secrets.** A row with `is_encrypted` holds its value encrypted at rest
 * under `APP_KEY` and is never rendered back to any client — see
 * {@see EventSettingResource}, which sends
 * `masked_value` and an `is_set` flag instead. That combination is what
 * makes it safe for a gateway credential to live here at all: CLAUDE.md's
 * standing rule is that such a credential must never sit in an
 * *unencrypted* `event_settings` row, and until now `is_encrypted` was a
 * column nothing implemented — no encryption on write, no decryption on
 * read, and the resource returned `value` verbatim.
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
        if ($this->is_encrypted) {
            return $this->decrypted();
        }

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

        if ($this->is_encrypted) {
            // A blank secret means "clear it", not "store an encrypted empty
            // string" — otherwise `hasValue()` would report a credential as
            // configured when it is not, and the resolver would hand out a
            // driver that fails on every message.
            $plain = trim((string) $value);

            return $plain === '' ? null : Crypt::encryptString($plain);
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

    /** Whether this row holds a credential rather than ordinary configuration. */
    public function isSecret(): bool
    {
        return (bool) $this->is_encrypted;
    }

    /** Whether a value is actually stored — the only thing a client is told about a secret. */
    public function hasValue(): bool
    {
        return $this->value !== null && $this->value !== '';
    }

    /**
     * The plaintext behind an encrypted row. Never put this in a response,
     * an activity log, or an exception message.
     *
     * Returns null rather than throwing when the ciphertext will not open —
     * an `APP_KEY` that was rotated without re-encrypting these rows would
     * otherwise take the entire settings screen down with a 500, hiding the
     * one page that explains what happened. The row reports itself as set,
     * because it is; what is lost is the ability to read it.
     */
    public function decrypted(): ?string
    {
        if (! $this->is_encrypted) {
            return $this->value;
        }

        if (! $this->hasValue()) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $this->value);
        } catch (Throwable) {
            Log::warning('Could not decrypt event setting; APP_KEY may have changed since it was saved.', [
                'key' => $this->key,
            ]);

            return null;
        }
    }

    /**
     * What a secret looks like on screen: the last four characters, so a
     * reader can tell *which* credential is stored without being shown it.
     * Short values are masked entirely rather than half-revealed.
     */
    public function maskedValue(): ?string
    {
        $plain = $this->decrypted();

        if ($plain === null || $plain === '') {
            return null;
        }

        return mb_strlen($plain) <= 8
            ? str_repeat('•', 8)
            : str_repeat('•', 8).mb_substr($plain, -4);
    }
}
