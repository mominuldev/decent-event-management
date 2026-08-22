<?php

namespace Tests\Feature\Auth;

use App\Domain\Notification\Models\Notification;
use App\Domain\Registration\Models\Attendee;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The SMS-code sign-in, for attendees who have no password — created
 * before passwords existed, added by an admin, or loaded by an import.
 * The ordinary password path and its cost properties live in
 * {@see AttendeePasswordAuthTest}.
 */
class AttendeeAuthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The code as the recipient gets it: out of the rendered SMS, not out
     * of the HTTP response. The response used to carry a `debug_token` in
     * local/testing, which let these tests pass without ever touching the
     * delivery path — so the sign-in SMS was going out with an empty body
     * and no drain job dispatched, and nothing noticed.
     */
    private function codeFromTheSms(): string
    {
        $notification = Notification::query()
            ->where('template_key', 'attendee.login_link')
            ->latest('id')
            ->sole();

        $this->assertSame('sms', $notification->channel);

        preg_match('/\b(\d{6})\b/', (string) $notification->body_rendered, $matches);

        $this->assertArrayHasKey(1, $matches, 'The SMS carried no sign-in code.');

        return $matches[1];
    }

    public function test_request_code_sends_a_usable_code_by_sms_and_never_in_the_response(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);

        $response = $this->postJson('/api/v1/attendee/auth/request-code', [
            'mobile' => '+8801711111111',
        ])->assertOk();

        // Returning the code would sign anyone in as this attendee just for
        // knowing their mobile number.
        $this->assertSame(['message'], array_keys((array) $response->json()));
        $this->assertNotNull($attendee->fresh()->auth_token_hash);

        $notification = Notification::query()->where('template_key', 'attendee.login_link')->sole();

        // Both of these were broken and invisible while the response carried
        // the token: no template meant an empty body, and a hand-written
        // outbox row meant no drain job.
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', (string) $notification->body_rendered);
        $this->assertNotSame('queued', $notification->status);
    }

    public function test_the_code_is_stored_hashed_not_in_the_clear(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        $attendee = Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();

        $code = $this->codeFromTheSms();

        // A database dump must not be a list of live sign-in codes.
        $this->assertNotSame($code, $attendee->fresh()->auth_token_hash);
        $this->assertSame(hash('sha256', $code), $attendee->fresh()->auth_token_hash);
    }

    public function test_a_second_request_is_not_swallowed_as_a_duplicate(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();

        // The outbox's usual dedupe key is (notifiable, template, channel),
        // which is right for a one-per-event notification and would drop the
        // second sign-in attempt on the floor.
        $this->assertSame(2, Notification::query()->where('template_key', 'attendee.login_link')->count());
    }

    public function test_request_code_for_unknown_number_gives_the_same_generic_response_and_sends_nothing(): void
    {
        $known = Attendee::factory()->create(['mobile' => '+8801711111111']);
        $this->seed(NotificationTemplateSeeder::class);

        $knownResponse = $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => $known->mobile])->assertOk();
        $unknownResponse = $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801799999999'])->assertOk();

        // Identical bodies, or this becomes a way to test which numbers hold
        // accounts. And an unknown number must not cost an SMS — otherwise
        // a script walking a number range spends real balance.
        $this->assertSame($knownResponse->getContent(), $unknownResponse->getContent());
        $this->assertSame(1, Notification::query()->where('template_key', 'attendee.login_link')->count());
    }

    public function test_verify_exchanges_a_valid_code_for_a_session(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        $code = $this->codeFromTheSms();

        $response = $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111',
            'code' => $code,
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'expires_at', 'attendee']);

        // Single-use: the same code cannot be replayed.
        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111',
            'code' => $code,
        ])->assertStatus(401);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/attendee/me')
            ->assertOk();
    }

    public function test_verify_rejects_an_unknown_code(): void
    {
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111',
            'code' => '000000',
        ])->assertStatus(401);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $this->seed(NotificationTemplateSeeder::class);
        Attendee::factory()->create(['mobile' => '+8801711111111']);

        $this->postJson('/api/v1/attendee/auth/request-code', ['mobile' => '+8801711111111'])->assertOk();
        $code = $this->codeFromTheSms();

        $this->travel(16)->minutes();

        $this->postJson('/api/v1/attendee/auth/verify', [
            'mobile' => '+8801711111111',
            'code' => $code,
        ])->assertStatus(401);
    }
}
