<?php

namespace Tests\Feature\Admin;

use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Notification\Support\SmsGatewayConfig;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use Database\Seeders\EventSettingSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Setting the SMS gateway credentials from the admin console.
 *
 * The rule these exist to protect: a credential in `event_settings` is only
 * acceptable because it is encrypted at rest *and* write-only across the
 * API. CLAUDE.md's standing instruction is that a gateway credential must
 * never sit in an unencrypted settings row — and `is_encrypted` was a
 * column nothing implemented until this landed, so every one of these
 * assertions would have failed before it.
 */
class SmsGatewaySettingsTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $this->seed(RbacSeeder::class);
        $this->seed(EventSettingSeeder::class);

        $user = User::factory()->create();
        $user->assignRole('Super Admin');

        return $user;
    }

    /** Admin routes are Sanctum-token guarded, not session guarded. */
    private function as(User $user): static
    {
        Sanctum::actingAs($user, ['admin'], 'web-admin');

        return $this;
    }

    private function setting(string $key): EventSetting
    {
        return EventSetting::query()->where('key', $key)->sole();
    }

    public function test_a_saved_api_key_is_encrypted_at_rest(): void
    {
        $this->as($this->superAdmin())
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => '34dd062b35bad338'])
            ->assertOk();

        $stored = $this->setting('sms.api_key');

        // The ciphertext, not the key — a database dump, a replica or a
        // backup must not hand over the account.
        $this->assertNotSame('34dd062b35bad338', $stored->value);
        $this->assertSame('34dd062b35bad338', Crypt::decryptString((string) $stored->value));
        $this->assertSame('34dd062b35bad338', $stored->decrypted());
    }

    public function test_the_api_never_sends_a_secret_back(): void
    {
        $admin = $this->superAdmin();

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.secret_key', ['value' => '9e138d90'])
            ->assertOk()
            // Even the response to the write that set it.
            ->assertJsonPath('data.value', null)
            ->assertJsonPath('data.typed_value', null)
            ->assertJsonPath('data.is_set', true)
            // Fully masked, not last-four: `9e138d90` is 8 characters, which
            // is what a real REVE secret key looks like. Revealing four of
            // them would cut the space left to guess from 16^8 to 16^4.
            ->assertJsonPath('data.masked_value', '••••••••');

        $body = $this->as($admin)
            ->getJson('/api/v1/admin/settings')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('9e138d90', (string) $body);
    }

    public function test_a_long_secret_shows_its_last_four_so_the_reader_knows_which_one_is_stored(): void
    {
        // A REVE apikey is 16 characters; four of them is enough to tell one
        // key from another and far too little to reconstruct it.
        $this->as($this->superAdmin())
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => '34dd062b35bad338'])
            ->assertOk()
            ->assertJsonPath('data.masked_value', '••••••••d338');
    }

    public function test_a_short_secret_is_masked_entirely_rather_than_half_revealed(): void
    {
        $this->as($this->superAdmin())
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => 'abc123'])
            ->assertOk()
            ->assertJsonPath('data.masked_value', '••••••••');
    }

    public function test_the_audit_log_records_the_change_without_the_credential(): void
    {
        $this->as($this->superAdmin())
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => 'super-secret-key'])
            ->assertOk();

        $log = ActivityLog::query()->where('log_name', 'setting')->sole();

        // activity_logs is append-only and has no redaction path, so anything
        // written here is there permanently — and it is read from the admin
        // console.
        $this->assertStringNotContainsString('super-secret-key', json_encode($log->properties) ?: '');
        $this->assertSame('[redacted]', $log->properties['new']['value']);
        $this->assertNull($log->properties['old']['value']);
    }

    public function test_saving_a_secret_blank_clears_it_rather_than_storing_an_empty_string(): void
    {
        $admin = $this->superAdmin();

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => 'a-key'])->assertOk();

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => ''])
            ->assertOk()
            ->assertJsonPath('data.is_set', false);

        // An encrypted empty string would report as "configured" and then fail
        // every send.
        $this->assertNull($this->setting('sms.api_key')->value);
    }

    public function test_an_event_manager_may_see_that_a_key_is_set_but_not_change_it(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(EventSettingSeeder::class);

        $manager = User::factory()->create();
        $manager->assignRole('Event Manager');

        $this->as($manager)->getJson('/api/v1/admin/settings')->assertOk();
        $this->as($manager)
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => 'nope'])
            ->assertForbidden();
    }

    // --- The settings actually drive the gateway -------------------------

    public function test_a_key_set_in_the_dashboard_beats_the_one_in_the_environment(): void
    {
        config([
            'services.revesms.api_key' => 'env-key',
            'services.revesms.secret_key' => 'env-secret',
            'services.revesms.sender_id' => 'ENVSENDER',
            'services.revesms.base_url' => 'https://smpp.revesms.com:7790',
        ]);

        $admin = $this->superAdmin();

        foreach ([
            'sms.api_key' => 'dashboard-key',
            'sms.secret_key' => 'dashboard-secret',
            // Numeric: non-masking is the default mode, and the validator
            // now holds the sender ID to it.
            'sms.sender_id' => '8809612',
        ] as $key => $value) {
            $this->as($admin)
                ->patchJson("/api/v1/admin/settings/{$key}", ['value' => $value])->assertOk();
        }

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        app(ReveSmsClient::class)->sendText(ReveSmsClient::defaultSenderId(), ['8801711223344'], 'Hi');

        Http::assertSent(fn ($request): bool => $request->data()['apikey'] === 'dashboard-key'
            && $request->data()['secretkey'] === 'dashboard-secret'
            && $request->data()['callerID'] === '8809612');
    }

    public function test_an_empty_setting_falls_back_to_the_environment_instead_of_breaking_it(): void
    {
        // Clearing a field on screen must not take down a deployment that was
        // configured through .env — blank means "not set here", not "unset".
        config([
            'services.revesms.api_key' => 'env-key',
            'services.revesms.secret_key' => 'env-secret',
            'services.revesms.sender_id' => 'ENVSENDER',
        ]);

        $this->superAdmin();

        $this->assertTrue(ReveSmsClient::isConfigured());
        $this->assertSame('ENVSENDER', ReveSmsClient::defaultSenderId());
    }

    public function test_changing_a_credential_applies_to_the_very_next_send(): void
    {
        $admin = $this->superAdmin();

        foreach (['sms.api_key' => 'first-key', 'sms.secret_key' => 's', 'sms.sender_id' => '8809612'] as $k => $v) {
            $this->as($admin)->patchJson("/api/v1/admin/settings/{$k}", ['value' => $v])->assertOk();
        }

        // Warm the memo, the way a real process would have.
        $this->assertSame('first-key', app(SmsGatewayConfig::class)->get('api_key'));

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.api_key', ['value' => 'second-key'])->assertOk();

        // Without the flush on write, the operator would reasonably conclude
        // the save had failed.
        $this->assertSame('second-key', app(SmsGatewayConfig::class)->get('api_key'));
    }

    public function test_a_setting_outside_the_gateway_map_cannot_be_used_to_override_config(): void
    {
        $this->superAdmin();

        // `auth_style` and `method` describe how the account was provisioned,
        // not something an operator tunes — a wrong value takes SMS off the
        // air entirely, so they are config-only by design.
        $this->assertFalse(SmsGatewayConfig::overridesGateway('sms.auth_style'));
        $this->assertTrue(SmsGatewayConfig::overridesGateway('sms.api_key'));
    }

    // --- Balance ---------------------------------------------------------

    public function test_the_balance_endpoint_reports_configured_false_before_any_key_is_set(): void
    {
        config(['services.revesms.api_key' => null, 'services.revesms.secret_key' => null, 'services.revesms.sender_id' => null]);

        $this->as($this->superAdmin())
            ->getJson('/api/v1/admin/notifications/sms-balance')
            ->assertOk()
            ->assertJsonPath('configured', false)
            ->assertJsonPath('balance', null)
            ->assertJsonPath('recharge_url', 'https://smpp.ajuratech.com');
    }

    public function test_the_balance_endpoint_reports_the_balance_and_a_low_warning(): void
    {
        config([
            'services.revesms.api_key' => 'k',
            'services.revesms.secret_key' => 's',
            'services.revesms.sender_id' => 'DEC100',
            'services.revesms.base_url' => 'https://smpp.revesms.com:7790',
        ]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['Status' => '0', 'balance' => '1420.50'])]);

        $this->as($this->superAdmin())
            ->getJson('/api/v1/admin/notifications/sms-balance')
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonPath('balance', 1420.5)
            // 142,050 paisa at the seeded 50 paisa per segment.
            ->assertJsonPath('estimated_segments', 2841)
            // Below the seeded ৳2,000 threshold.
            ->assertJsonPath('is_low', true);
    }

    public function test_an_unreachable_gateway_is_a_502_not_a_zero_balance(): void
    {
        config([
            'services.revesms.api_key' => 'k',
            'services.revesms.secret_key' => 's',
            'services.revesms.sender_id' => 'DEC100',
        ]);

        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('down', 502)]);

        // "We could not ask" and "the account is empty" lead an operator to
        // opposite actions, so they must not render the same.
        $this->as($this->superAdmin())
            ->getJson('/api/v1/admin/notifications/sms-balance')
            ->assertStatus(502)
            ->assertJsonPath('code', 'sms_gateway_unreachable');
    }

    public function test_the_balance_needs_the_costs_permission(): void
    {
        $this->seed(RbacSeeder::class);
        $this->seed(EventSettingSeeder::class);

        $volunteer = User::factory()->create();
        $volunteer->assignRole('Volunteer');

        $this->as($volunteer)
            ->getJson('/api/v1/admin/notifications/sms-balance')
            ->assertForbidden();
    }

    // --- Masking vs non-masking -------------------------------------------

    private function setMasking(bool $on): void
    {
        $setting = $this->setting(SmsGatewayConfig::MASKING_KEY);
        $setting->value = $on ? '1' : '0';
        $setting->save();
        app(SmsGatewayConfig::class)->flush();
    }

    public function test_non_masking_is_the_default(): void
    {
        $this->superAdmin();

        // It is what an account can send without the operator approving a
        // brand name first, so it must not require one to work.
        $this->assertFalse(app(SmsGatewayConfig::class)->maskingEnabled());
    }

    public function test_a_numeric_sender_is_accepted_when_masking_is_off(): void
    {
        $admin = $this->superAdmin();
        $this->setMasking(false);

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.sender_id', ['value' => '8809612'])
            ->assertOk();
    }

    public function test_a_brand_name_is_refused_while_masking_is_off(): void
    {
        $admin = $this->superAdmin();
        $this->setMasking(false);

        // The gateway accepts either string and it fails later at the
        // carrier, which is far harder to diagnose than a 422 in the field
        // somebody is looking at.
        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.sender_id', ['value' => 'DEC100'])
            ->assertStatus(422)
            ->assertJsonPath('errors.value.0', fn (string $m): bool => str_contains($m, 'digits only'));
    }

    public function test_a_brand_name_is_accepted_once_masking_is_on(): void
    {
        $admin = $this->superAdmin();
        $this->setMasking(true);

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.sender_id', ['value' => 'DEC100'])
            ->assertOk();
    }

    public function test_a_masking_sender_longer_than_eleven_characters_is_refused(): void
    {
        $admin = $this->superAdmin();
        $this->setMasking(true);

        // GSM 03.38 caps it at 11. The gateway does not refuse a longer one;
        // the carrier drops it.
        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.sender_id', ['value' => 'DECENTCENTENNIAL'])
            ->assertStatus(422);
    }

    public function test_a_sender_id_is_required_in_both_modes(): void
    {
        $this->superAdmin();

        // callerID is mandatory either way — omitting it answers
        // `114 Inappropriate request parameter` and submits nothing. The
        // mode decides the shape of the value, never whether it is needed.
        config(['services.revesms.api_key' => 'k', 'services.revesms.secret_key' => 's', 'services.revesms.sender_id' => null]);

        $this->setMasking(false);
        $this->assertSame(['SMS sender ID'], ReveSmsClient::missingCredentials());

        $this->setMasking(true);
        $this->assertSame(['SMS sender ID'], ReveSmsClient::missingCredentials());
    }

    public function test_flipping_the_masking_toggle_applies_immediately(): void
    {
        $admin = $this->superAdmin();
        $this->setMasking(false);

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/'.SmsGatewayConfig::MASKING_KEY, ['value' => true])
            ->assertOk();

        // Without the flush on write, the sender-ID validator on the very
        // next save would still be judging against the old mode.
        $this->assertTrue(app(SmsGatewayConfig::class)->maskingEnabled());

        $this->as($admin)
            ->patchJson('/api/v1/admin/settings/sms.sender_id', ['value' => 'DEC100'])
            ->assertOk();
    }
}
