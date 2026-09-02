<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\EventSettingCatalogue;
use Database\Seeders\EventSettingSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The Settings screen renders `config/event_settings.php`, not the
 * `event_settings` table, and saving a setting is what creates its row.
 *
 * The failure this closes was reported from the live server: the whole SMS
 * gateway group was missing from Settings, so the credentials could not be
 * entered at all. Nothing was broken — those keys were added to
 * `EventSettingSeeder` after that environment had last been seeded, the
 * deploy runs migrations and no seeders, and `PATCH /admin/settings/{key}`
 * answered 404 for a key with no row. So a setting added in a release was
 * both invisible and unaddable until somebody SSH'd in and re-seeded.
 *
 * Every test here runs with **no settings rows seeded** unless it says
 * otherwise — that is the state a live environment is in for any key added
 * since its last seed, and it is the state the old code got wrong.
 */
class SettingCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->admin = User::factory()->create(['name' => 'Ayesha Rahman', 'status' => 'active']);
        $this->admin->assignRole('Super Admin');

        Sanctum::actingAs($this->admin, ['admin'], 'web-admin');
    }

    public function test_the_screen_lists_every_catalogued_setting_with_no_rows_at_all(): void
    {
        $this->assertSame(0, EventSetting::query()->count());

        $data = $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(200)->json('data');

        $listed = collect($data)->flatten(1)->pluck('key')->sort()->values()->all();
        $catalogued = collect(array_keys(EventSettingCatalogue::definitions()))->sort()->values()->all();

        $this->assertSame($catalogued, $listed);

        // The group that was actually missing on the live server.
        $this->assertArrayHasKey('sms', $data);
        $this->assertContains('sms.api_key', collect($data['sms'])->pluck('key')->all());
        $this->assertContains('sms.sender_id', collect($data['sms'])->pluck('key')->all());
    }

    public function test_an_unsaved_setting_shows_its_catalogue_default(): void
    {
        $data = $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(200)->json('data');

        $row = collect($data['payment'])->firstWhere('key', 'payment.intent_ttl_minutes');
        $this->assertSame(30, $row['typed_value']);
        $this->assertSame('Payment intent TTL (minutes)', $row['label']);

        // An unsaved row must serialise to the same shape as a saved one, or
        // the client has two cases to handle for one screen.
        $this->assertNull($row['updated_at']);
        $this->assertArrayHasKey('updated_by', $row);
        $this->assertNull($row['updated_by']);

        // A credential with no row yet reports itself as not set, and still
        // sends no value — the write-only rule does not depend on a row.
        $secret = collect($data['sms'])->firstWhere('key', 'sms.api_key');
        $this->assertTrue($secret['is_secret']);
        $this->assertFalse($secret['is_set']);
        $this->assertNull($secret['value']);
        $this->assertNull($secret['masked_value']);
    }

    public function test_saving_a_setting_that_has_no_row_creates_it(): void
    {
        $this->assertDatabaseMissing('event_settings', ['key' => 'sms.sender_id']);

        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'sms.sender_id']), ['value' => '8809612'])
            ->assertStatus(200)
            ->assertJsonPath('data.value', '8809612')
            ->assertJsonPath('data.updated_by', 'Ayesha Rahman');

        // Created with the catalogue's metadata, not guessed from the request.
        $this->assertDatabaseHas('event_settings', [
            'key' => 'sms.sender_id',
            'group' => 'sms',
            'type' => 'string',
            'label' => 'SMS sender ID (callerID)',
            'is_public' => false,
            'updated_by_user_id' => $this->admin->id,
        ]);
    }

    /**
     * The load-bearing one. `castForStorage()` branches on `is_encrypted`,
     * which used to come from the row being edited — so a first save with no
     * row would have written a gateway credential to the table in plaintext,
     * in a column that is read back by the settings screen, copied into every
     * replica and included in every nightly `db:backup`.
     */
    public function test_a_first_save_of_a_credential_is_encrypted_and_never_read_back(): void
    {
        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'sms.api_key']), ['value' => '34dd062b35bad338'])
            ->assertStatus(200)
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.typed_value', null)
            ->assertJsonPath('data.is_set', true);

        $stored = EventSetting::query()->where('key', 'sms.api_key')->sole();

        $this->assertTrue($stored->is_encrypted);
        $this->assertNotSame('34dd062b35bad338', $stored->value);
        $this->assertSame('34dd062b35bad338', Crypt::decryptString((string) $stored->value));
    }

    public function test_a_first_save_is_validated_against_the_catalogue_type(): void
    {
        // Without catalogue-driven resolution these all fall through to the
        // "plain text" arm and are accepted, then throw from `typedValue()`
        // on the next read — taking the whole screen down away from the
        // mistake that caused it.
        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'payment.intent_ttl_minutes']), ['value' => 'ten'])
            ->assertStatus(422)->assertJsonValidationErrors('value');

        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'checkin.allow_manual_override']), ['value' => 'maybe'])
            ->assertStatus(422)->assertJsonValidationErrors('value');

        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'registration.closes_at']), ['value' => 'not a date'])
            ->assertStatus(422)->assertJsonValidationErrors('value');

        $this->assertSame(0, EventSetting::query()->count());

        // And the same key accepts a good value, stored in its canonical form.
        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'checkin.allow_manual_override']), ['value' => false])
            ->assertStatus(200)->assertJsonPath('data.typed_value', false);

        $this->assertSame('0', EventSetting::query()->where('key', 'checkin.allow_manual_override')->sole()->value);
    }

    public function test_creating_a_setting_is_audited_as_having_had_no_previous_value(): void
    {
        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'event.support_phone']), ['value' => '+8801711223344'])
            ->assertStatus(200);

        $log = ActivityLog::query()->where('log_name', 'setting')->sole();

        $this->assertNull($log->properties['old']);
        $this->assertSame('+8801711223344', $log->properties['new']['value']);
    }

    public function test_a_key_that_is_neither_stored_nor_catalogued_is_still_a_404(): void
    {
        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'not.a.setting']), ['value' => 'x'])
            ->assertStatus(404);
    }

    public function test_a_stored_row_keeps_its_value_and_takes_its_wording_from_the_catalogue(): void
    {
        // A row seeded by an older release: the value is the admin's, the
        // label and description are the code's and have since improved.
        EventSetting::factory()->create([
            'key' => 'registration.max_family_size',
            'group' => 'registration',
            'type' => 'int',
            'value' => '9',
            'label' => 'Wording from an older release',
            'description' => 'Stale.',
        ]);

        $data = $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(200)->json('data');
        $row = collect($data['registration'])->firstWhere('key', 'registration.max_family_size');

        $this->assertSame(9, $row['typed_value']);
        $this->assertSame('Max family size', $row['label']);
        $this->assertStringStartsWith('Largest party', (string) $row['description']);

        // Reading refreshes the wording on screen; it does not quietly
        // rewrite the row behind the reader's back.
        $this->assertSame('Wording from an older release', EventSetting::query()->where('key', 'registration.max_family_size')->sole()->label);
    }

    public function test_a_stored_row_the_catalogue_does_not_define_still_shows_and_still_saves(): void
    {
        EventSetting::factory()->create([
            'key' => 'branding.site_title',
            'group' => 'branding',
            'type' => 'string',
            'value' => 'Old Title',
            'label' => 'Site title',
        ]);

        $data = $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(200)->json('data');

        $this->assertSame('Site title', collect($data['branding'])->firstWhere('key', 'branding.site_title')['label']);

        $this->patchJson(route('api.v1.admin.settings.update', ['key' => 'branding.site_title']), ['value' => 'New Title'])
            ->assertStatus(200)
            ->assertJsonPath('data.value', 'New Title');
    }

    public function test_seeding_produces_exactly_what_the_screen_already_showed(): void
    {
        $before = collect($this->getJson(route('api.v1.admin.settings.index'))->json('data'))
            ->flatten(1)
            ->mapWithKeys(fn (array $row) => [$row['key'] => [$row['group'], $row['type'], $row['label'], $row['is_public']]]);

        $this->seed(EventSettingSeeder::class);

        $after = collect($this->getJson(route('api.v1.admin.settings.index'))->json('data'))
            ->flatten(1)
            ->mapWithKeys(fn (array $row) => [$row['key'] => [$row['group'], $row['type'], $row['label'], $row['is_public']]]);

        $this->assertSame($before->all(), $after->all());
        $this->assertSame(count(EventSettingCatalogue::definitions()), EventSetting::query()->count());
    }

    /**
     * `event_settings` is narrow — the columns are 64/32/16/120/255 — and an
     * over-long description fails the save with a raw SQL error rather than a
     * field message. That has broken `db:seed` on a fresh deployment before,
     * and now that these strings are written on a *save* it would break the
     * Settings screen too.
     */
    public function test_every_catalogue_entry_fits_the_columns_it_is_stored_in(): void
    {
        foreach (EventSettingCatalogue::definitions() as $key => $definition) {
            $this->assertLessThanOrEqual(64, mb_strlen($key), "Key too long: {$key}");
            $this->assertLessThanOrEqual(32, mb_strlen((string) $definition['group']), "Group too long: {$key}");
            $this->assertLessThanOrEqual(16, mb_strlen((string) $definition['type']), "Type too long: {$key}");
            $this->assertLessThanOrEqual(120, mb_strlen((string) $definition['label']), "Label too long: {$key}");
            $this->assertLessThanOrEqual(255, mb_strlen((string) ($definition['description'] ?? '')), "Description too long: {$key}");

            $this->assertContains(
                $definition['type'],
                ['string', 'int', 'money', 'bool', 'datetime', 'json'],
                "Unknown type on {$key} — EventSetting::typedValue() would fall through to plain text.",
            );
        }
    }
}
