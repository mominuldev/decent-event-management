<?php

namespace App\Domain\Notification\Support;

use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Shared\Models\EventSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Where {@see ReveSmsClient} gets its credentials: the `sms` group in
 * `event_settings` first, falling back to `config/services.php` (and so
 * to `.env`) for anything not set there.
 *
 * **The database wins deliberately.** The point of putting these on the
 * settings screen is that an operator can change the sender ID or rotate a
 * key at 9pm the night before the event without a deploy, so a value
 * present in the database has to beat the one baked into the image. The
 * `.env` path stays as the fallback because it is the only one that works
 * before the seeder has run, in a queue worker on a box with no console,
 * and in every test that has no settings rows.
 *
 * A blank setting is *not* an override — it is "not configured here", so
 * clearing a field on the screen falls back to `.env` rather than breaking
 * a deployment that was configured that way.
 *
 * Values are memoised per resolved instance, and the instance is a
 * container singleton, so one request or one queue job reads the table
 * once. {@see self::flush()} is called whenever an `sms` setting is
 * written, so an edit takes effect on the very next send rather than
 * after a cache TTL nobody can see.
 */
class SmsGatewayConfig
{
    /** The `event_settings.group` these rows live in. */
    public const string GROUP = 'sms';

    /**
     * Setting key => the `services.revesms.*` key it overrides. Anything
     * absent here is config-only and cannot be set from the dashboard —
     * `auth_style` and `method` among them, deliberately: they describe how
     * the account was provisioned, not something an operator tunes, and a
     * wrong value takes SMS off the air entirely.
     *
     * @var array<string, string>
     */
    private const array KEY_MAP = [
        'sms.api_key' => 'api_key',
        'sms.secret_key' => 'secret_key',
        'sms.sender_id' => 'sender_id',
        'sms.base_url' => 'base_url',
        'sms.client_id' => 'client_id',
        'sms.cost_paisa_per_segment' => 'cost_paisa_per_segment',
    ];

    /**
     * Whether the account sends with an alphanumeric brand name rather
     * than a number. Not in {@see self::KEY_MAP} because it is not a
     * gateway credential — `callerID` is sent either way (omitting it is
     * `114 Inappropriate request parameter`); this only decides what shape
     * belongs in it. See {@see SmsSenderId}.
     */
    public const string MASKING_KEY = 'sms.masking_enabled';

    /** @var array<string, string>|null */
    private ?array $overrides = null;

    private ?bool $masking = null;

    public function get(string $key, ?string $default = null): ?string
    {
        $value = $this->overrides()[$key] ?? null;

        if ($value === null || $value === '') {
            $value = config("services.revesms.{$key}", $default);
        }

        if ($value === null || $value === '') {
            return $default;
        }

        return is_scalar($value) ? (string) $value : $default;
    }

    public function flush(): void
    {
        $this->overrides = null;
        $this->masking = null;
    }

    public function maskingEnabled(): bool
    {
        if ($this->masking !== null) {
            return $this->masking;
        }

        try {
            if (! Schema::hasTable('event_settings')) {
                return $this->masking = false;
            }

            $row = EventSetting::query()->where('key', self::MASKING_KEY)->first();
        } catch (Throwable) {
            return $this->masking = false;
        }

        // Non-masking is the default: it is what an account can send
        // without the operator approving a brand name first.
        return $this->masking = $row !== null && $row->typedValue() === true;
    }

    /**
     * @return array<string, string>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        $this->overrides = [];

        try {
            // A queue worker booting against a database that has not been
            // migrated yet, or an `artisan` call during deployment, must not
            // fatal here — it falls back to config, which is exactly the
            // behaviour before any of this existed.
            if (! Schema::hasTable('event_settings')) {
                return $this->overrides;
            }

            $rows = EventSetting::query()->where('group', self::GROUP)->get();
        } catch (Throwable) {
            return $this->overrides;
        }

        foreach ($rows as $row) {
            $mapped = self::KEY_MAP[$row->key] ?? null;

            if ($mapped === null) {
                continue;
            }

            $value = $row->isSecret() ? $row->decrypted() : $row->value;

            if ($value !== null && $value !== '') {
                $this->overrides[$mapped] = $value;
            }
        }

        return $this->overrides;
    }

    /** Whether a given settings key feeds the gateway, so a write knows to flush. */
    public static function overridesGateway(string $settingKey): bool
    {
        return array_key_exists($settingKey, self::KEY_MAP) || $settingKey === self::MASKING_KEY;
    }
}
