<?php

namespace App\Domain\Shared\Support;

use App\Domain\Shared\Models\EventSetting;
use Illuminate\Support\Collection;

/**
 * Reads `config/event_settings.php` — the catalogue of every setting the
 * admin console can show and save — and turns it into {@see EventSetting}
 * models, whether or not a row for that key exists yet.
 *
 * **Why this exists.** The Settings screen used to render
 * `EventSetting::all()`, so a setting appeared only once somebody had
 * re-run `EventSettingSeeder` on that environment. The deploy runs
 * migrations and no seeders, so a key added in a release was invisible on
 * production — and unfixable from the console, because
 * `PATCH /admin/settings/{key}` did `firstOrFail()` and answered 404 for a
 * key with no row. That is how the whole SMS gateway group went missing on
 * the live server. The catalogue is now the code-owned definition and the
 * table holds only values: a setting shows up on deploy, and saving it is
 * what writes the row.
 *
 * The division of ownership, which every method here follows:
 *
 * - **Metadata is code-owned** — group, type, label, description,
 *   visibility, whether it is a secret. Refreshed from config on read, so
 *   an improved description reaches every environment on deploy.
 * - **The value is admin-owned** — only ever written by somebody saving on
 *   the Settings screen, or seeded for a row that does not exist yet.
 *
 * A key with a row but no catalogue entry is left exactly as it is. Such a
 * row is either older than this file or created by a test, and silently
 * dropping it from the screen would be worse than showing it with whatever
 * metadata it carries.
 */
class EventSettingCatalogue
{
    /**
     * The catalogue, in the order the screen should show each group's rows.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        /** @var array<string, array<string, mixed>> $definitions */
        $definitions = config('event_settings', []);

        return $definitions;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /**
     * The code-owned half of a catalogued row, shaped for
     * {@see EventSetting::fill()}. Null for a key this file does not define.
     *
     * @return array<string, mixed>|null
     */
    public static function metadataFor(string $key): ?array
    {
        $definition = self::definitions()[$key] ?? null;

        if ($definition === null) {
            return null;
        }

        return [
            'key' => $key,
            'group' => (string) $definition['group'],
            'type' => (string) $definition['type'],
            'is_encrypted' => (bool) ($definition['is_encrypted'] ?? false),
            'is_public' => (bool) ($definition['is_public'] ?? false),
            'label' => (string) $definition['label'],
            'description' => $definition['description'] ?? null,
        ];
    }

    /**
     * Refreshes the code-owned metadata on a row, in memory. A no-op for an
     * uncatalogued key, so a legacy or test-created row keeps its own.
     */
    public static function applyMetadata(EventSetting $setting): EventSetting
    {
        $metadata = self::metadataFor((string) $setting->key);

        if ($metadata !== null) {
            $setting->fill($metadata);
        }

        return $setting;
    }

    /**
     * A row for a catalogued key that has never been saved: real metadata,
     * the catalogue's default as its value, and no id.
     *
     * The metadata is filled *before* the value, because
     * {@see EventSetting::castForStorage()} branches on `is_encrypted` —
     * setting the value first would narrow a credential as if it were
     * ordinary text.
     */
    public static function make(string $key): ?EventSetting
    {
        $metadata = self::metadataFor($key);

        if ($metadata === null) {
            return null;
        }

        $setting = new EventSetting;
        $setting->fill($metadata);
        $setting->value = $setting->castForStorage(self::definitions()[$key]['default'] ?? null);

        // So the resource renders `updated_by: null` rather than omitting the
        // key entirely, which is what `whenLoaded()` would do on a model with
        // no relation loaded — a saved and an unsaved row must serialise to
        // the same shape or the client has two cases to handle.
        $setting->setRelation('updatedBy', null);

        return $setting;
    }

    /**
     * The row behind a key: the stored one with its metadata refreshed, or
     * an unsaved catalogue-backed one, or null when the key is neither
     * stored nor catalogued (which is the only 404 case left).
     */
    public static function resolve(string $key): ?EventSetting
    {
        $stored = EventSetting::query()->where('key', $key)->first();

        if ($stored !== null) {
            return self::applyMetadata($stored);
        }

        return self::make($key);
    }

    /**
     * Every setting the console should show: the whole catalogue in declared
     * order, carrying stored values where rows exist, followed by any stored
     * row this file does not define.
     *
     * @return Collection<int, EventSetting>
     */
    public static function all(): Collection
    {
        $stored = EventSetting::query()->with('updatedBy')->get()->keyBy('key');

        /** @var Collection<int, EventSetting> $settings */
        $settings = new Collection;

        foreach (array_keys(self::definitions()) as $key) {
            $row = $stored->get($key);

            $setting = $row instanceof EventSetting
                ? self::applyMetadata($row)
                : self::make($key);

            if ($setting !== null) {
                $settings->push($setting);
            }
        }

        foreach ($stored as $key => $row) {
            if (! self::has((string) $key)) {
                $settings->push($row);
            }
        }

        return $settings;
    }
}
