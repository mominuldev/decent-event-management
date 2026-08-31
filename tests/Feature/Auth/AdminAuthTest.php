<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    /**
     * A stored password the hasher cannot read — pasted into a database
     * client, or imported from another system — used to raise an unhandled
     * RuntimeException out of Hash::check(), so a public endpoint answered
     * 500 (a stack trace, with APP_DEBUG on) where 401 belongs.
     */
    public function test_login_answers_401_rather_than_500_when_the_stored_hash_is_unreadable(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        // Straight to the column, so the `hashed` cast cannot save us — which
        // is exactly how a row edited outside the application arrives.
        DB::table('users')->where('id', $user->id)->update(['password' => 'plaintext-not-a-hash']);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'plaintext-not-a-hash',
        ]);

        $response->assertStatus(401)->assertJson(['message' => 'Invalid credentials.']);
        $this->assertStringNotContainsString('Bcrypt', (string) $response->getContent());
    }

    /**
     * It is broken, not under attack. Counting it would lock the account after
     * five attempts and answer 423, moving the message further still from the
     * cause.
     */
    public function test_an_unreadable_hash_does_not_count_towards_the_login_lockout(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);
        DB::table('users')->where('id', $user->id)->update([
            'password' => 'plaintext-not-a-hash',
            'failed_login_attempts' => 0,
        ]);

        foreach (range(1, 6) as $ignored) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $user->email,
                'password' => 'whatever',
            ])->assertStatus(401);
        }

        $user->refresh();

        $this->assertSame(0, $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
    }

    public function test_a_wrong_password_against_a_readable_hash_still_counts(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'wrong',
        ])->assertStatus(401);

        $this->assertSame(1, $user->refresh()->failed_login_attempts);
    }

    /**
     * Five tries then a pause, not one try for ever. The counter used to
     * survive the lockout it caused, so the first mistype after every cooldown
     * re-locked the account immediately.
     */
    public function test_the_attempt_counter_starts_again_once_a_lockout_has_elapsed(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        foreach (range(1, 5) as $ignored) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ]);
        }

        $this->assertNotNull($user->refresh()->locked_until, 'five wrong tries must lock it');

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-again',
        ])->assertStatus(401);

        $user->refresh();

        $this->assertSame(1, $user->failed_login_attempts, 'the served lockout resets the count');
        $this->assertNull($user->locked_until, 'one mistype after a cooldown must not re-lock');
    }

    public function test_login_with_correct_password_and_no_2fa_returns_setup_only_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertOk()
            ->assertJson(['requires_2fa_setup' => true])
            ->assertJsonStructure(['token', 'expires_at', 'user']);
    }

    public function test_login_with_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_account_locks_after_five_failed_attempts(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/admin/auth/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);
        }

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ]);

        $response->assertStatus(423);
    }

    public function test_full_2fa_setup_and_confirm_flow(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $login = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $setupToken = $login->json('token');

        $setup = $this->withToken($setupToken)
            ->postJson('/api/v1/admin/auth/2fa/setup')
            ->assertOk()
            ->assertJsonStructure(['secret', 'qr_code_svg']);

        $secret = $setup->json('secret');
        $code = (new Google2FA)->getCurrentOtp($secret);

        $confirm = $this->withToken($setupToken)
            ->postJson('/api/v1/admin/auth/2fa/confirm', ['code' => $code])
            ->assertOk()
            ->assertJsonStructure(['token', 'recovery_codes']);

        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        // The next login must supply a TOTP code.
        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertStatus(401);

        $secondCode = (new Google2FA)->getCurrentOtp($secret);

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'totp_code' => $secondCode,
        ])->assertOk()->assertJson(['requires_2fa_setup' => false]);
    }

    public function test_setup_only_token_cannot_access_full_admin_routes(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $login = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
        ])->assertOk();

        $this->withToken($login->json('token'))
            ->getJson('/api/v1/admin/settings')
            ->assertStatus(403);
    }
}
