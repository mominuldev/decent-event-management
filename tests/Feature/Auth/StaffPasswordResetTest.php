<?php

namespace Tests\Feature\Auth;

use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Shared\Models\User;
use App\Mail\StaffPasswordResetMail;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const string OLD_PASSWORD = 'the-original-password';

    private const string NEW_PASSWORD = 'a-brand-new-password';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);

        $this->user = User::factory()->create([
            'email' => 'staff@example.com',
            'password' => self::OLD_PASSWORD,
            'status' => 'active',
        ]);
        $this->user->syncRoles(['Event Manager']);
    }

    /** @param array<string, mixed> $payload */
    private function forgot(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/forgot-password', $payload);
    }

    /** @param array<string, mixed> $payload */
    private function reset(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/admin/auth/reset-password', $payload);
    }

    // ---- requesting a link ----

    public function test_it_emails_a_reset_link_to_an_active_account(): void
    {
        Mail::fake();

        $this->forgot(['email' => 'staff@example.com'])->assertOk();

        Mail::assertSent(StaffPasswordResetMail::class, fn (StaffPasswordResetMail $mail): bool => $mail->hasTo('staff@example.com'));
    }

    /**
     * The link must be built from config('app.url'). A request-supplied origin
     * would let somebody mail a real staff member a real token pointing at a
     * site they control.
     */
    public function test_the_link_is_built_from_the_configured_app_url(): void
    {
        Mail::fake();
        config(['app.url' => 'https://portal.example.test']);

        $this->withHeaders(['Host' => 'evil.example.com'])
            ->postJson('/api/v1/admin/auth/forgot-password', ['email' => 'staff@example.com'])
            ->assertOk();

        Mail::assertSent(StaffPasswordResetMail::class, function (StaffPasswordResetMail $mail): bool {
            return str_starts_with($mail->resetUrl, 'https://portal.example.test/reset-password?token=')
                && ! str_contains($mail->resetUrl, 'evil.example.com');
        });
    }

    public function test_an_unknown_address_gets_the_same_answer_and_no_email(): void
    {
        Mail::fake();

        $known = $this->forgot(['email' => 'staff@example.com']);
        $unknown = $this->forgot(['email' => 'nobody@example.com']);

        $unknown->assertOk();
        // Byte-identical, or the response itself says who works here.
        $this->assertSame($known->getContent(), $unknown->getContent());

        Mail::assertSentCount(1);
    }

    public function test_a_suspended_account_is_silently_skipped(): void
    {
        Mail::fake();
        $this->user->forceFill(['status' => 'suspended'])->save();

        $this->forgot(['email' => 'staff@example.com'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_a_soft_deleted_account_is_silently_skipped(): void
    {
        Mail::fake();
        $this->user->delete();

        $this->forgot(['email' => 'staff@example.com'])->assertOk();

        Mail::assertNothingSent();
    }

    public function test_the_address_is_matched_case_insensitively(): void
    {
        Mail::fake();

        $this->forgot(['email' => '  STAFF@Example.COM '])->assertOk();

        Mail::assertSent(StaffPasswordResetMail::class);
    }

    /** Cost control second; stopping inbox flooding and address enumeration first. */
    public function test_requests_for_one_address_are_rate_limited(): void
    {
        Mail::fake();

        foreach (range(1, 3) as $ignored) {
            $this->forgot(['email' => 'staff@example.com'])->assertOk();
        }

        $this->forgot(['email' => 'staff@example.com'])->assertStatus(429);
    }

    /**
     * A broken mailer must not become an oracle. Before this, the exception
     * escaped: 500 for an address with an account, 200 for one without.
     */
    public function test_a_mailer_failure_does_not_change_the_answer(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp is down'));

        $known = $this->forgot(['email' => 'staff@example.com']);
        $unknown = $this->forgot(['email' => 'nobody@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->getContent(), $unknown->getContent());
    }

    // ---- using the link ----

    /** The whole round trip: the URL that was emailed actually works. */
    public function test_the_emailed_link_sets_a_new_password_that_logs_in(): void
    {
        Mail::fake();
        $this->forgot(['email' => 'staff@example.com'])->assertOk();

        $token = null;
        Mail::assertSent(StaffPasswordResetMail::class, function (StaffPasswordResetMail $mail) use (&$token): bool {
            parse_str((string) parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;

            return true;
        });

        $this->assertNotNull($token);

        $this->reset([
            'token' => $token,
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $this->user->refresh()->password));

        $this->postJson('/api/v1/admin/auth/login', [
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    /**
     * Reading a mailbox is not the second factor. A reset must leave the
     * caller signed out, so the next login still goes through 2FA.
     */
    public function test_a_reset_does_not_sign_you_in(): void
    {
        $response = $this->reset([
            'token' => Password::broker()->createToken($this->user),
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_it_revokes_every_existing_session(): void
    {
        $this->user->createToken('a-device', ['admin']);
        $this->user->createToken('another-device', ['admin']);
        $this->assertSame(2, $this->user->tokens()->count());

        $this->reset([
            'token' => Password::broker()->createToken($this->user),
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->assertSame(0, $this->user->tokens()->count());
    }

    public function test_it_clears_a_login_lockout(): void
    {
        $this->user->forceFill([
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(15),
        ])->save();

        $this->reset([
            'token' => Password::broker()->createToken($this->user),
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $this->user->refresh();

        $this->assertSame(0, $this->user->failed_login_attempts);
        $this->assertNull($this->user->locked_until);
    }

    public function test_a_token_works_only_once(): void
    {
        $token = Password::broker()->createToken($this->user);
        $payload = [
            'token' => $token,
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ];

        $this->reset($payload)->assertOk();
        $this->reset($payload)->assertStatus(422)->assertJsonValidationErrors('token');
    }

    public function test_an_expired_token_is_refused(): void
    {
        $token = Password::broker()->createToken($this->user);
        $minutes = (int) config('auth.passwords.users.expire', 60);

        $this->travel($minutes + 1)->minutes();

        $this->reset([
            'token' => $token,
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertStatus(422)->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->refresh()->password));
    }

    /**
     * A wrong token and an address with no account answer identically, so this
     * endpoint cannot be used to confirm who holds one either.
     */
    public function test_a_bad_token_and_an_unknown_address_are_indistinguishable(): void
    {
        $badToken = $this->reset([
            'token' => 'not-a-real-token',
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $unknownUser = $this->reset([
            'token' => 'not-a-real-token',
            'email' => 'nobody@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ]);

        $badToken->assertStatus(422);
        $unknownUser->assertStatus(422);
        $this->assertSame($badToken->getContent(), $unknownUser->getContent());
    }

    public function test_the_new_password_must_meet_the_staff_minimum(): void
    {
        $this->reset([
            'token' => Password::broker()->createToken($this->user),
            'email' => 'staff@example.com',
            'password' => 'short1234',
            'password_confirmation' => 'short1234',
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertTrue(Hash::check(self::OLD_PASSWORD, $this->user->refresh()->password));
    }

    public function test_the_confirmation_must_match(): void
    {
        $this->reset([
            'token' => Password::broker()->createToken($this->user),
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => 'something-else-entirely',
        ])->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_it_is_audited_as_a_reset_and_never_records_the_password(): void
    {
        $this->reset([
            'token' => Password::broker()->createToken($this->user),
            'email' => 'staff@example.com',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertOk();

        $log = ActivityLog::where('event', 'password_reset')->firstOrFail();

        $this->assertSame('warning', $log->severity);
        $this->assertSame($this->user->id, $log->subject_id);
        $this->assertStringNotContainsString(self::NEW_PASSWORD, json_encode($log->properties) ?: '');
    }
}
