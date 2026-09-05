<?php

namespace Tests\Feature\Auth;

use App\Domain\Registration\Models\Attendee;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Signing in by email, and the account-existence check.
 *
 * Both arrived together because both answer the same question — "which
 * attendee is this?" — and both are new surface on routes that were
 * previously mobile-only.
 */
class AttendeeEmailIdentifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationTemplateSeeder::class);
        RateLimiter::clear('ip:127.0.0.1');
    }

    private function attendee(string $password = 'correct-horse-battery'): Attendee
    {
        $attendee = Attendee::factory()->create([
            'mobile' => '+8801711111111',
            'email' => 'rahim@example.com',
        ]);

        $attendee->forceFill(['password' => $password, 'password_set_at' => now()])->save();

        return $attendee->refresh();
    }

    public function test_an_attendee_can_sign_in_with_their_email(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.auth.login'), [
            'email' => 'rahim@example.com',
            'password' => 'correct-horse-battery',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'expires_at', 'attendee' => ['ulid']]);
    }

    /** Stored lowercase, so the casing someone types must not matter. */
    public function test_email_sign_in_is_case_insensitive(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.auth.login'), [
            'email' => '  Rahim@Example.COM  ',
            'password' => 'correct-horse-battery',
        ])->assertStatus(200);
    }

    public function test_the_mobile_path_still_works(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.auth.login'), [
            'mobile' => '01711111111',
            'password' => 'correct-horse-battery',
        ])->assertStatus(200);
    }

    /**
     * The same 401 as a wrong password. An unknown address must not be
     * distinguishable here — that is what `check` is for, deliberately and
     * behind its own throttle.
     */
    public function test_an_unknown_email_is_refused_indistinguishably(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.auth.login'), [
            'email' => 'nobody@example.com',
            'password' => 'correct-horse-battery',
        ])
            ->assertStatus(401)
            ->assertJsonPath('message', 'Those details do not match an account.');
    }

    public function test_neither_identifier_is_a_validation_error(): void
    {
        $this->postJson(route('api.v1.attendee.auth.login'), ['password' => 'whatever'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile', 'email']);
    }

    // ---------------------------------------------------------------
    // The existence check
    // ---------------------------------------------------------------

    public function test_check_reports_a_registered_mobile_and_email(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.auth.check'), ['mobile' => '01711111111'])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', true);

        $this->postJson(route('api.v1.attendee.auth.check'), ['email' => 'rahim@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', true);
    }

    /**
     * 200 with `false`, never a 404 — the frontend reads a 404 as "this
     * route is not deployed" and silently falls back to asking nothing.
     */
    public function test_check_answers_two_hundred_for_an_unknown_identifier(): void
    {
        $this->postJson(route('api.v1.attendee.auth.check'), ['mobile' => '01999999999'])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', false);
    }

    /** A removed account must not report itself as still here. */
    public function test_check_does_not_reveal_a_soft_deleted_attendee(): void
    {
        $this->attendee()->delete();

        $this->postJson(route('api.v1.attendee.auth.check'), ['email' => 'rahim@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('data.exists', false);
    }

    public function test_check_requires_an_identifier(): void
    {
        $this->postJson(route('api.v1.attendee.auth.check'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile', 'email']);
    }
}
