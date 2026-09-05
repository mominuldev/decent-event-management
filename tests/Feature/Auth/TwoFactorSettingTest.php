<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\User;
use App\Domain\Shared\Support\TwoFactorPolicy;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

/**
 * Staff 2FA is a switch an admin owns — `security.two_factor_enabled` — not
 * an unconditional requirement. These cases pin both positions of it, and
 * the one that matters most: switching it off must let an account that
 * already enrolled back in, because otherwise a lost authenticator is
 * unrecoverable without shell access.
 */
class TwoFactorSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    private function setEnforcement(bool $enforced): void
    {
        EventSetting::query()->updateOrCreate(
            ['key' => TwoFactorPolicy::SETTING_KEY],
            ['group' => 'security', 'type' => 'bool', 'label' => 'Require 2FA', 'value' => $enforced ? '1' : '0'],
        );

        app(TwoFactorPolicy::class)->flush();
    }

    /** An account already carrying a confirmed enrolment. */
    private function enrolledUser(string $secret): User
    {
        return User::factory()->create([
            'password' => bcrypt('correct-password'),
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
        ]);
    }

    public function test_no_setting_row_means_2fa_is_off_and_login_returns_a_full_token(): void
    {
        $this->assertDatabaseMissing('event_settings', ['key' => TwoFactorPolicy::SETTING_KEY]);

        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])
            ->assertOk()
            ->assertJson(['requires_2fa_setup' => false]);
    }

    /**
     * The token really is a full one, not just labelled as such: a
     * setup-only token is refused by every route outside the 2FA endpoints.
     */
    public function test_a_login_with_2fa_off_reaches_ordinary_admin_routes(): void
    {
        $this->setEnforcement(false);

        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $user->assignRole('Super Admin');

        $token = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->json('token');

        $this->withToken($token)->getJson('/api/v1/admin/settings')->assertOk();
    }

    public function test_switching_it_on_sends_an_unenrolled_account_to_setup(): void
    {
        $this->setEnforcement(true);

        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])
            ->assertOk()
            ->assertJson(['requires_2fa_setup' => true]);
    }

    public function test_switching_it_on_makes_an_enrolled_account_supply_its_code(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = $this->enrolledUser($secret);

        $this->setEnforcement(true);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'totp_code' => (new Google2FA)->getCurrentOtp($secret),
        ])
            ->assertOk()
            ->assertJson(['requires_2fa_setup' => false]);
    }

    /**
     * The recovery case the switch exists for. Enrolment is left on the row
     * untouched, so turning it back on picks up where it left off.
     */
    public function test_switching_it_off_lets_an_enrolled_account_in_without_a_code(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = $this->enrolledUser($secret);

        $this->setEnforcement(false);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])
            ->assertOk()
            ->assertJson(['requires_2fa_setup' => false]);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at, 'the enrolment survives the switch');
        $this->assertNotNull($user->fresh()->two_factor_secret);
    }

    /** A wrong password is still a wrong password with the switch off. */
    public function test_switching_it_off_does_not_weaken_the_password_check(): void
    {
        $this->setEnforcement(false);

        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_reauth_needs_a_code_only_while_the_switch_is_on(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = $this->enrolledUser($secret);
        $user->assignRole('Super Admin');

        $this->setEnforcement(false);

        $token = $user->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/reauth', ['password' => 'correct-password'])
            ->assertOk()
            ->assertJson(['confirmed' => true]);

        $this->setEnforcement(true);

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/reauth', ['password' => 'correct-password'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('totp_code');

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/reauth', [
                'password' => 'correct-password',
                'totp_code' => (new Google2FA)->getCurrentOtp($secret),
            ])
            ->assertOk();
    }

    /**
     * Enrolment is deliberately available while the switch is off, so an
     * account can be ready before anyone flips it.
     */
    public function test_an_account_may_enrol_while_the_switch_is_off(): void
    {
        $this->setEnforcement(false);

        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        $user->assignRole('Super Admin');

        $token = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->json('token');

        $secret = $this->withToken($token)
            ->postJson('/api/v1/admin/auth/2fa/setup')
            ->assertOk()
            ->json('secret');

        $this->withToken($token)
            ->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => (new Google2FA)->getCurrentOtp($secret)])
            ->assertOk();

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // Still not asked for it, because the switch is what decides.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk()->assertJson(['requires_2fa_setup' => false]);
    }

    /**
     * The saved value has to reach the next login. The policy is memoised
     * for the life of the process, so without the controller's flush an
     * operator would turn 2FA on and watch the next sign-in ignore it.
     */
    public function test_saving_the_setting_takes_effect_without_a_restart(): void
    {
        $admin = User::factory()->create(['password' => bcrypt('correct-password')]);
        $admin->assignRole('Super Admin');

        // Resolve the singleton and read it, so a stale memo would survive.
        $this->assertFalse(app(TwoFactorPolicy::class)->enforced());

        Sanctum::actingAs($admin, ['admin'], 'web-admin');

        $this->patchJson('/api/v1/admin/settings/'.TwoFactorPolicy::SETTING_KEY, ['value' => true])
            ->assertOk();

        $this->assertTrue(app(TwoFactorPolicy::class)->enforced());
    }
}
