<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * `PATCH /admin/settings/{key}` validates against the row's own declared
 * `type` and narrows the input to one canonical stored form. Before this, a
 * `datetime` setting accepted any string, and the bad value only surfaced as
 * a parse error from `EventSetting::typedValue()` on the next read — taking
 * the whole settings screen down rather than failing at the bad save.
 */
class SettingUpdateTest extends TestCase
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

    private function patchSetting(EventSetting $setting, mixed $value): TestResponse
    {
        return $this->patchJson(
            route('api.v1.admin.settings.update', ['key' => $setting->key]),
            ['value' => $value],
        );
    }

    public function test_index_exposes_the_type_visibility_and_last_editor(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'event.date',
            'group' => 'event',
            'type' => 'datetime',
            'value' => '2027-02-14 06:00:00',
            'is_public' => true,
            'updated_by_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson(route('api.v1.admin.settings.index'))->assertStatus(200);

        // Located by key, not by position: the index renders the whole
        // catalogue in its declared order, so this row's index moves whenever
        // a setting is added to the `event` group.
        $row = collect($response->json('data.event'))->firstWhere('key', $setting->key);

        $this->assertNotNull($row);
        $this->assertSame('datetime', $row['type']);
        $this->assertTrue($row['is_public']);
        $this->assertSame('Ayesha Rahman', $row['updated_by']);
    }

    public function test_public_endpoint_never_names_the_staff_member_who_last_edited(): void
    {
        EventSetting::factory()->create([
            'key' => 'event.name',
            'type' => 'string',
            'value' => 'Centennial',
            'is_public' => true,
            'updated_by_user_id' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/public/event')->assertStatus(200);

        $this->assertArrayNotHasKey('updated_by', $response->json('data.0'));
        $response->assertJsonMissing(['updated_by' => 'Ayesha Rahman']);
    }

    public function test_an_integer_setting_rejects_a_non_integer(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'registration.max_family_size',
            'type' => 'int',
            'value' => '6',
        ]);

        $this->patchSetting($setting, 'six')->assertStatus(422)->assertJsonValidationErrors('value');

        $this->assertSame('6', $setting->fresh()?->value);
    }

    public function test_a_datetime_setting_rejects_garbage_and_normalises_what_it_accepts(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'registration.closes_at',
            'type' => 'datetime',
            'value' => '2027-01-01 00:00:00',
        ]);

        $this->patchSetting($setting, 'not a date')->assertStatus(422)->assertJsonValidationErrors('value');
        $this->assertSame('2027-01-01 00:00:00', $setting->fresh()?->value);

        // The SPA sends an instant; it is stored in the app timezone (UTC),
        // never at whatever offset the editor's browser happened to use.
        $this->patchSetting($setting, '2027-02-14T12:30:00+06:00')->assertStatus(200);

        $this->assertSame('2027-02-14 06:30:00', $setting->fresh()?->value);
    }

    public function test_a_boolean_setting_stores_one_canonical_form(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'notification.sms_enabled',
            'type' => 'bool',
            'value' => '1',
        ]);

        $this->patchSetting($setting, false)->assertStatus(200)->assertJsonPath('data.typed_value', false);
        $this->assertSame('0', $setting->fresh()?->value);

        $this->patchSetting($setting, true)->assertStatus(200)->assertJsonPath('data.typed_value', true);
        $this->assertSame('1', $setting->fresh()?->value);

        $this->patchSetting($setting, 'maybe')->assertStatus(422)->assertJsonValidationErrors('value');
    }

    public function test_a_json_setting_rejects_a_string_that_is_not_json(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'event.hero_slides',
            'type' => 'json',
            'value' => '[]',
        ]);

        $this->patchSetting($setting, '{not json')->assertStatus(422)->assertJsonValidationErrors('value');

        $this->patchSetting($setting, ['a' => 1])->assertStatus(200);
        $this->assertSame(['a' => 1], $setting->fresh()?->typedValue());
    }

    public function test_updating_records_the_editor_and_an_activity_log_entry(): void
    {
        $setting = EventSetting::factory()->create([
            'key' => 'event.venue',
            'type' => 'string',
            'value' => 'Old Hall',
        ]);

        $this->patchSetting($setting, 'School Campus')
            ->assertStatus(200)
            ->assertJsonPath('data.value', 'School Campus')
            ->assertJsonPath('data.updated_by', 'Ayesha Rahman');

        $this->assertSame($this->admin->id, $setting->fresh()?->updated_by_user_id);

        $this->assertDatabaseHas('activity_logs', [
            'log_name' => 'setting',
            'event' => 'updated',
            'subject_id' => $setting->id,
        ]);
    }
}
