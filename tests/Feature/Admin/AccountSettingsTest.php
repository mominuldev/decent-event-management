<?php

namespace Tests\Feature\Admin;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A staff member's own account settings: name/email/phone, and password.
 *
 * Authenticated with a real bearer token rather than Sanctum::actingAs,
 * because the password endpoint keeps the session performing the change and
 * revokes the rest — which needs a real PersonalAccessToken to identify.
 */
class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-battery';

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->user = User::factory()->create([
            'name' => 'Original Name',
            'email' => 'staff@example.com',
            'phone' => null,
            'password' => self::PASSWORD,
        ]);
        $this->user->syncRoles(['Event Manager']);

        $this->token = $this->user->createToken('test', ['admin'])->plainTextToken;
    }

    /** @param array<string, mixed> $payload */
    private function patchProfile(array $payload): TestResponse
    {
        return $this->withToken($this->token)->patchJson('/api/v1/admin/auth/me', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function postPassword(array $payload): TestResponse
    {
        return $this->withToken($this->token)->postJson('/api/v1/admin/auth/password', $payload);
    }

    // ---- profile ----

    public function test_it_updates_name_email_and_phone(): void
    {
        $this->patchProfile([
            'name' => 'Mominul Islam',
            'email' => 'new@example.com',
            'phone' => '+8801711022299',
        ])->assertOk()->assertJson([
            'name' => 'Mominul Islam',
            'email' => 'new@example.com',
            'phone' => '+8801711022299',
        ]);

        $this->user->refresh();

        $this->assertSame('Mominul Islam', $this->user->name);
        $this->assertSame('new@example.com', $this->user->email);
        $this->assertSame('+8801711022299', $this->user->phone);
    }

    public function test_the_response_carries_roles_and_permissions_so_the_console_need_not_refetch(): void
    {
        $this->patchProfile(['name' => 'Someone Else', 'email' => 'staff@example.com'])
            ->assertOk()
            ->assertJsonStructure(['ulid', 'name', 'email', 'phone', 'roles', 'permissions']);
    }

    public function test_it_refuses_an_email_another_staff_account_holds(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->patchProfile(['name' => 'Original Name', 'email' => 'taken@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        $this->assertSame('staff@example.com', $this->user->refresh()->email);
    }

    /** Ignoring self, or saving the form without touching the email would fail. */
    public function test_keeping_your_own_email_is_not_a_conflict(): void
    {
        $this->patchProfile(['name' => 'Renamed', 'email' => 'staff@example.com'])->assertOk();

        $this->assertSame('Renamed', $this->user->refresh()->name);
    }

    public function test_the_email_is_trimmed_and_lowercased(): void
    {
        $this->patchProfile(['name' => 'Original Name', 'email' => '  MiXeD@Example.COM '])->assertOk();

        $this->assertSame('mixed@example.com', $this->user->refresh()->email);
    }

    /** The column is VARCHAR(150); over-long must be a field error, never a 500. */
    public function test_an_over_long_name_is_a_field_error(): void
    {
        $this->patchProfile(['name' => str_repeat('a', 151), 'email' => 'staff@example.com'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_phone_may_be_cleared(): void
    {
        $this->user->forceFill(['phone' => '+8801711022299'])->save();

        $this->patchProfile(['name' => 'Original Name', 'email' => 'staff@example.com', 'phone' => null])
            ->assertOk();

        $this->assertNull($this->user->refresh()->phone);
    }

    public function test_a_profile_change_is_audited_but_an_unchanged_save_is_not(): void
    {
        $this->patchProfile(['name' => 'Original Name', 'email' => 'staff@example.com'])->assertOk();
        $this->assertSame(0, ActivityLog::where('event', 'profile_updated')->count());

        $this->patchProfile(['name' => 'Changed', 'email' => 'staff@example.com'])->assertOk();

        $log = ActivityLog::where('event', 'profile_updated')->firstOrFail();

        $this->assertSame($this->user->id, $log->causer_id);
        $this->assertSame(['name'], $log->properties['changed']);
    }

    // ---- password ----

    public function test_it_changes_the_password(): void
    {
        $this->postPassword([
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->user->refresh();

        $this->assertTrue(Hash::check('a-brand-new-password', $this->user->password));
        $this->assertFalse(Hash::check(self::PASSWORD, $this->user->password));
    }

    public function test_the_wrong_current_password_changes_nothing(): void
    {
        $this->postPassword([
            'current_password' => 'not-my-password',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->refresh()->password));
    }

    public function test_the_new_password_must_meet_the_staff_minimum(): void
    {
        $this->postPassword([
            'current_password' => self::PASSWORD,
            'password' => 'short1234',
            'password_confirmation' => 'short1234',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->postPassword([
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-different-password',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    /**
     * A password changed because a laptop went missing achieves nothing while
     * the tokens minted on it still work.
     */
    public function test_it_revokes_every_other_session_and_keeps_this_one(): void
    {
        $other = $this->user->createToken('another-device', ['admin'])->plainTextToken;

        $this->postPassword([
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk()->assertJson(['other_sessions_revoked' => 1]);

        // The guard caches the user it resolved for the previous request, so
        // without this the next call is answered from that cache and never
        // re-checks the token it was handed.
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/v1/admin/auth/me')->assertStatus(401);

        $this->app['auth']->forgetGuards();
        $this->withToken($this->token)->getJson('/api/v1/admin/auth/me')->assertOk();
    }

    /** Changing your password must not leave you locked out by the attempts that led to it. */
    public function test_it_clears_a_login_lockout(): void
    {
        $this->user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $this->postPassword([
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $this->user->refresh();

        $this->assertSame(0, $this->user->failed_login_attempts);
        $this->assertNull($this->user->locked_until);
    }

    public function test_the_audit_row_records_the_change_but_never_the_password(): void
    {
        $this->postPassword([
            'current_password' => self::PASSWORD,
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertOk();

        $log = ActivityLog::where('event', 'password_changed')->firstOrFail();

        $this->assertSame('warning', $log->severity);
        $this->assertSame($this->user->id, $log->causer_id);
        $this->assertStringNotContainsString('a-brand-new-password', json_encode($log->properties) ?: '');
        $this->assertStringNotContainsString(self::PASSWORD, json_encode($log->properties) ?: '');
    }

    // ---- access ----

    public function test_both_endpoints_require_authentication(): void
    {
        $this->patchJson('/api/v1/admin/auth/me', ['name' => 'x', 'email' => 'x@example.com'])->assertStatus(401);
        $this->postJson('/api/v1/admin/auth/password', [])->assertStatus(401);
    }

    /**
     * A 2fa-setup token exists to finish setting 2FA up. It must not be able
     * to change the email you sign in with, or the password it protects.
     */
    public function test_a_two_factor_setup_token_cannot_reach_them(): void
    {
        $setupToken = $this->user->createToken('setup', ['2fa-setup'])->plainTextToken;

        $this->withToken($setupToken)
            ->patchJson('/api/v1/admin/auth/me', ['name' => 'x', 'email' => 'x@example.com'])
            ->assertStatus(403);

        $this->withToken($setupToken)
            ->postJson('/api/v1/admin/auth/password', [
                'current_password' => self::PASSWORD,
                'password' => 'a-brand-new-password',
                'password_confirmation' => 'a-brand-new-password',
            ])->assertStatus(403);

        $this->assertTrue(Hash::check(self::PASSWORD, $this->user->refresh()->password));
    }

    /** No permission gates these — every staff member owns their own account. */
    public function test_a_volunteer_with_no_admin_permissions_can_still_edit_their_own_account(): void
    {
        $volunteer = User::factory()->create(['password' => self::PASSWORD]);
        $volunteer->syncRoles(['Volunteer']);
        $token = $volunteer->createToken('test', ['admin'])->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/v1/admin/auth/me', ['name' => 'Volunteer Renamed', 'email' => $volunteer->email])
            ->assertOk();
    }
}
