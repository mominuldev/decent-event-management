<?php

namespace App\Domain\Shared\Support;

use App\Domain\Shared\Models\EventSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The single answer to "is staff two-factor authentication in force?".
 *
 * 2FA used to be unconditional (docs/02 §2.2), with a `local`-only bypass in
 * the login controller as the only escape. That is now a switch an admin
 * owns — `security.two_factor_enabled` on the Settings screen — because the
 * mandatory version has one failure mode nobody can recover from without
 * shell access: a staff member who loses their authenticator is locked out
 * of an account only that account can disable 2FA on.
 *
 * **Off is the default**, so a fresh environment signs in on a password
 * alone. Turning it on takes effect on the next login; nothing needs
 * deploying and no existing enrolment is lost, because the secret and
 * `two_factor_confirmed_at` stay on the user row whichever way this reads.
 *
 * Enrolment is deliberately *not* gated on this. `POST /admin/auth/2fa/setup`
 * and `/confirm` work while it is off, so an account can enrol ahead of the
 * switch being flipped. Note the admin SPA has no voluntary-enrolment screen
 * yet — it reaches the setup page only when a login says enrolment is
 * required — so today that ordering is available over the API only, and
 * turning the switch on walks everybody through setup at their next login.
 *
 * Memoised per instance, and the instance is a container singleton, so one
 * request reads the table once. {@see self::flush()} runs whenever the
 * setting is saved.
 */
class TwoFactorPolicy
{
    public const string SETTING_KEY = 'security.two_factor_enabled';

    private ?bool $enforced = null;

    /** Whether a staff login must present a TOTP code as well as a password. */
    public function enforced(): bool
    {
        if ($this->enforced !== null) {
            return $this->enforced;
        }

        return $this->enforced = $this->read();
    }

    public function flush(): void
    {
        $this->enforced = null;
    }

    private function read(): bool
    {
        try {
            // A migrate-only environment, or a queue worker booting mid-deploy,
            // has no `event_settings` table yet. Same guard as
            // SmsGatewayConfig: fall back to the catalogue rather than fatal.
            if (! Schema::hasTable('event_settings')) {
                return $this->catalogueDefault();
            }

            $row = EventSetting::query()->where('key', self::SETTING_KEY)->first();
        } catch (Throwable $e) {
            // Defensive only: the caller has already queried `users` by the
            // time it asks this, so a database that answers here at all has
            // answered once already.
            Log::warning('Could not read the staff 2FA setting; falling back to its catalogue default.', [
                'key' => self::SETTING_KEY,
                'exception' => $e->getMessage(),
            ]);

            return $this->catalogueDefault();
        }

        if ($row === null) {
            return $this->catalogueDefault();
        }

        return $row->typedValue() === true;
    }

    /**
     * What an unsaved setting reads as. Taken from `config/event_settings.php`
     * rather than hardcoded here, so the value the Settings screen shows for a
     * row nobody has saved and the value this enforces cannot drift apart.
     */
    private function catalogueDefault(): bool
    {
        $default = EventSettingCatalogue::definitions()[self::SETTING_KEY]['default'] ?? false;

        return filter_var($default, FILTER_VALIDATE_BOOLEAN);
    }
}
