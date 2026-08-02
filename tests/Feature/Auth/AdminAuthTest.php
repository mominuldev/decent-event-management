<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
