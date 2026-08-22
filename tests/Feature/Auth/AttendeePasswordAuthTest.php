<?php

namespace Tests\Feature\Auth;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Ticketing\Models\TicketType;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Attendee sign-in: a password chosen at checkout, and an SMS code only
 * for the people who have no password to use.
 *
 * The cost model is the reason this exists — every SMS is billed, and the
 * old design sent one on every single sign-in. These tests pin the two
 * things that keep the bill near zero: that a password login sends
 * nothing, and that the code route cannot be used to spend balance freely.
 */
class AttendeePasswordAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(NotificationTemplateSeeder::class);
        RateLimiter::clear('mobile:+8801711111111');
    }

    private function attendeeWithPassword(string $password = 'correct-horse-battery'): Attendee
    {
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);
        $attendee->forceFill(['password' => $password, 'password_set_at' => now()])->save();

        return $attendee->refresh();
    }

    // --- The ordinary path: no SMS at all ---------------------------------

    public function test_a_password_sign_in_costs_no_sms(): void
    {
        $this->attendeeWithPassword();

        $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801711111111',
            'password' => 'correct-horse-battery',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'attendee' => ['ulid', 'full_name', 'mobile']])
            ->assertJsonPath('must_set_password', false);

        // The whole point of the design.
        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_national_form_of_the_number_signs_in_too(): void
    {
        $this->attendeeWithPassword();

        // Nobody types +880 into a login box on their own phone.
        $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '01711111111',
            'password' => 'correct-horse-battery',
        ])->assertOk();
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->attendeeWithPassword();

        $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801711111111',
            'password' => 'not-it',
        ])->assertStatus(401);
    }

    public function test_an_unknown_number_and_a_wrong_password_are_indistinguishable(): void
    {
        $this->attendeeWithPassword();

        $wrongPassword = $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801711111111', 'password' => 'not-it',
        ]);
        $unknownNumber = $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801799999999', 'password' => 'not-it',
        ]);

        // Any difference here is a way to discover which mobile numbers hold
        // accounts.
        $this->assertSame($wrongPassword->status(), $unknownNumber->status());
        $this->assertSame($wrongPassword->getContent(), $unknownNumber->getContent());
    }

    public function test_an_attendee_without_a_password_cannot_sign_in_with_a_blank_one(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        foreach (['', 'anything'] as $attempt) {
            $this->postJson('/api/v1/attendee/auth/login', [
                'mobile' => '+8801711111111',
                'password' => $attempt,
            ])->assertStatus($attempt === '' ? 422 : 401);
        }
    }

    // --- Password chosen at checkout --------------------------------------

    public function test_a_password_set_at_checkout_signs_in_afterwards(): void
    {
        $this->registrationPayload(password: 'checkout-password-1');

        $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801733333333',
            'password' => 'checkout-password-1',
        ])->assertOk();
    }

    public function test_registering_again_cannot_overwrite_an_existing_password(): void
    {
        $this->registrationPayload(password: 'the-real-owners-password');

        // The attack this blocks: POST /public/registrations is
        // unauthenticated and resolves a returning registrant by mobile, so
        // a path that reset the password here would hand any account to
        // anyone who knows the number.
        $this->registrationPayload(password: 'attacker-chosen-password');

        $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801733333333', 'password' => 'attacker-chosen-password',
        ])->assertStatus(401);

        $this->postJson('/api/v1/attendee/auth/login', [
            'mobile' => '+8801733333333', 'password' => 'the-real-owners-password',
        ])->assertOk();
    }

    public function test_a_registration_without_a_password_still_succeeds(): void
    {
        // Admin tools, imports and any older client send none.
        $this->registrationPayload(password: null)->assertCreated();

        $this->assertFalse(Attendee::where('mobile', '+8801733333333')->sole()->hasPassword());
    }

    public function test_a_short_or_unconfirmed_password_is_refused_at_checkout(): void
    {
        $this->registrationPayload(password: 'short', confirm: 'short')->assertStatus(422);
        $this->registrationPayload(password: 'long-enough-1', confirm: 'different-1')->assertStatus(422);
    }

    // --- The SMS code path, for those with no password --------------------

    private function codeFromTheSms(): string
    {
        $body = (string) Notification::query()->latest('id')->sole()->body_rendered;
        preg_match('/\b(\d{6})\b/', $body, $m);
        $this->assertArrayHasKey(1, $m, "No six-digit code in the SMS: {$body}");

        return $m[1];
    }

    public function test_a_code_signs_in_and_asks_for_a_password(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();

        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111',
            'code' => $this->codeFromTheSms(),
        ])
            ->assertOk()
            // So the client knows to ask — leaving them signed in without a
            // password means another paid SMS next time.
            ->assertJsonPath('must_set_password', true);
    }

    public function test_the_code_is_one_segment_of_sms(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();

        $body = (string) Notification::query()->sole()->body_rendered;

        // The link this replaced was three segments in Bangla. A regression
        // here is a silent 3x on every code sent.
        $this->assertSame(1, SmsSegmentCalculator::segmentCount($body));
        $this->assertStringNotContainsString('http', $body);
    }

    public function test_the_code_is_burned_after_five_wrong_guesses(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);
        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        $code = $this->codeFromTheSms();

        // Six digits is a million guesses. The ceiling, not the length, is
        // what makes a code short enough for one segment safe to send.
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/attendee/auth/verify', [
                'mobile' => '+8801711111111', 'code' => '000000',
            ])->assertStatus(401);
        }

        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111', 'code' => $code,
        ])->assertStatus(401);
    }

    public function test_one_attendees_code_cannot_open_another_account(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);
        $victim = Attendee::factory()->create(['mobile' => '+8801722222222']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        $code = $this->codeFromTheSms();

        // A six-digit code is not unique across attendees, which is exactly
        // why verify() takes the mobile as well.
        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => $victim->mobile, 'code' => $code,
        ])->assertStatus(401);
    }

    public function test_the_sms_route_is_throttled_hard_because_it_spends_money(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        }

        // Previously this sat on the shared 60/min api bucket, which let one
        // caller spend roughly 2,000 BDT an hour of prepaid balance.
        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])
            ->assertStatus(429);

        $this->assertSame(3, Notification::query()->count());
    }

    // --- Setting a password once signed in --------------------------------

    public function test_a_first_password_needs_no_current_one(): void
    {
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);
        $token = $attendee->createToken('t', ['attendee'])->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/attendee/auth/password', [
            'password' => 'my-new-password-1',
            'password_confirmation' => 'my-new-password-1',
        ])->assertOk();

        $this->assertTrue($attendee->refresh()->hasPassword());
        $this->assertTrue(Hash::check('my-new-password-1', (string) $attendee->password));
    }

    public function test_changing_an_existing_password_requires_the_current_one(): void
    {
        $attendee = $this->attendeeWithPassword();
        $token = $attendee->createToken('t', ['attendee'])->plainTextToken;

        // A bearer token outlives the moment it was issued; a borrowed phone
        // must not be able to lock its owner out.
        $this->withToken($token)->postJson('/api/v1/attendee/auth/password', [
            'password' => 'attacker-password-1',
            'password_confirmation' => 'attacker-password-1',
        ])->assertStatus(422);

        $this->withToken($token)->postJson('/api/v1/attendee/auth/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'my-new-password-1',
            'password_confirmation' => 'my-new-password-1',
        ])->assertOk();
    }

    public function test_setting_a_password_revokes_every_other_session(): void
    {
        $attendee = $this->attendeeWithPassword();
        $keep = $attendee->createToken('this-one', ['attendee'])->plainTextToken;
        $attendee->createToken('somewhere-else', ['attendee']);

        $this->assertSame(2, $attendee->tokens()->count());

        $this->withToken($keep)->postJson('/api/v1/attendee/auth/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'my-new-password-1',
            'password_confirmation' => 'my-new-password-1',
        ])->assertOk();

        // Changing a password is what someone does when they think a session
        // is not theirs. It has to actually end them.
        $this->assertSame(1, $attendee->tokens()->count());
    }

    public function test_setting_a_password_spends_any_outstanding_code(): void
    {
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);
        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        $code = $this->codeFromTheSms();

        $token = $attendee->createToken('t', ['attendee'])->plainTextToken;
        $this->withToken($token)->postJson('/api/v1/attendee/auth/password', [
            'password' => 'my-new-password-1',
            'password_confirmation' => 'my-new-password-1',
        ])->assertOk();

        // A reset SMS from before the change must not still work.
        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111', 'code' => $code,
        ])->assertStatus(401);
    }

    /**
     * A minimal valid public registration, optionally carrying a password.
     */
    private function registrationPayload(?string $password, ?string $confirm = null): TestResponse
    {
        $ticketType = TicketType::factory()->create([
            'is_active' => true,
            'is_public' => true,
            'sale_starts_at' => now()->subDay(),
            'sale_ends_at' => now()->addMonth(),
            'allowed_participant_types' => [],
        ]);

        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/public/registrations', array_filter([
                'ticket_type_ulid' => $ticketType->ulid,
                'full_name' => 'Test Registrant',
                'full_name_bn' => 'টেস্ট নিবন্ধক',
                'father_name' => 'Test Father',
                'occupation' => 'Teacher',
                'current_address' => 'Chapainawabganj',
                'mobile' => '+8801733333333',
                'gender' => 'male',
                'participant_type' => 'former_student',
                'ssc_batch_year' => 2005,
                'participation_type' => 'single',
                'adults_count' => 1,
                'children_count' => 0,
                'idempotency_key' => (string) Str::uuid(),
                'password' => $password,
                'password_confirmation' => $password === null ? null : ($confirm ?? $password),
            ], fn ($v) => $v !== null));
    }
}
