<?php

namespace Database\Seeders;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Support\EventSettingCatalogue;
use Illuminate\Database\Seeder;

/**
 * Materialises `config/event_settings.php` as rows.
 *
 * **This is no longer where a setting is defined** — the catalogue is, and
 * the admin console reads it directly, so a setting appears on the Settings
 * screen and can be saved there whether or not this seeder has ever run on
 * that environment. See {@see EventSettingCatalogue} for why that changed.
 *
 * Running it is still useful — it gives a fresh install working defaults
 * out of the box, and it is what `db:seed` does — but forgetting to run it
 * no longer hides anything.
 */
class EventSettingSeeder extends Seeder
{
    public function run(): void
    {
        foreach (EventSettingCatalogue::definitions() as $key => $definition) {
            $setting = EventSetting::firstOrNew(['key' => $key]);

            // Metadata (label, description, type, visibility) is code-owned and
            // always refreshed. The *value* is admin-owned: seeding only
            // supplies it for a row that does not exist yet, so re-running the
            // seeder can never silently revert a date or a kill switch someone
            // set deliberately on the Settings screen.
            //
            // Fill the metadata first: `castForStorage()` branches on
            // `is_encrypted`, so seeding a value before that flag is set
            // would write a credential to the table in plaintext.
            EventSettingCatalogue::applyMetadata($setting);

            if (! $setting->exists) {
                $setting->value = $setting->castForStorage($definition['default'] ?? null);
            }

            $setting->save();
        }
    }
}
