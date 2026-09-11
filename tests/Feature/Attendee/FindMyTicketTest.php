<?php

namespace Tests\Feature\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\ActivityLog;
use App\Domain\Ticketing\Models\QrCode;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * The passwordless "find my ticket" lookup.
 *
 * Two halves, and the second matters more than the first. That a correct
 * name opens the record is the feature; that the resulting session is
 * strictly weaker than a real sign-in is the reason the feature is
 * acceptable at all, so most of what follows asserts what a lookup token
 * *cannot* do.
 */
class FindMyTicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('ip:127.0.0.1');
    }

    private function attendee(array $overrides = []): Attendee
    {
        return Attendee::factory()->create([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
            'mobile' => '+8801711111111',
            'email' => 'rahim@example.com',
            ...$overrides,
        ]);
    }

    /** @return array{0: Attendee, 1: string} */
    private function openSession(array $body = []): array
    {
        $attendee = $this->attendee();

        $response = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
            ...$body,
        ])->assertStatus(200);

        return [$attendee, $response->json('token')];
    }

    private function ticketedRegistration(Attendee $attendee): Ticket
    {
        $ticketType = TicketType::factory()->create();

        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'confirmed',
        ]);

        $ticket = Ticket::factory()->create([
            'registration_id' => $registration->id,
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'active',
        ]);

        QrCode::factory()->create([
            'ticket_id' => $ticket->id,
            'payload' => 'DTM1.'.$ticket->ulid.'.2.'.now()->addYear()->timestamp.'.key-1.sig',
            'is_active' => true,
        ]);

        return $ticket;
    }

    // ---------------------------------------------------------------- open

    public function test_a_mobile_number_and_the_registered_name_open_a_session(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
        ])
            ->assertStatus(200)
            ->assertJsonStructure(['token', 'expires_at', 'attendee' => ['ulid', 'full_name']]);
    }

    public function test_an_email_address_works_in_place_of_the_mobile_number(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'email' => 'RAHIM@Example.com',
            'full_name' => 'Rahim Uddin',
        ])->assertStatus(200);
    }

    /**
     * Case, punctuation and doubled spaces are presentation, not identity —
     * somebody reading their own name off a printout should not be refused
     * for typing it the way they write it.
     */
    public function test_the_name_is_matched_past_case_punctuation_and_spacing(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => '  rahim,   UDDIN ',
        ])->assertStatus(200);
    }

    public function test_the_bangla_name_opens_the_same_record(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'রহিম উদ্দিন',
        ])->assertStatus(200);
    }

    /**
     * The name is the only secret here, so it is matched whole. A given
     * name that opened a record would make the credential little more than
     * the phone number on its own.
     */
    public function test_a_partial_name_does_not_open_the_record(): void
    {
        $this->attendee();

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim',
        ])->assertStatus(404);
    }

    public function test_an_empty_name_never_matches_even_a_record_with_no_name(): void
    {
        $this->attendee(['full_name' => '', 'full_name_bn' => null]);

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => '   ',
        ])->assertStatus(422);
    }

    /**
     * A wrong name and an unregistered number answer identically. Anything
     * else turns this into a way to read the name off a record by watching
     * which guess changes the response.
     */
    public function test_a_wrong_name_is_indistinguishable_from_an_unknown_number(): void
    {
        $this->attendee();

        $wrongName = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Karim Uddin',
        ])->assertStatus(404);

        $unknownNumber = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01999999999',
            'full_name' => 'Karim Uddin',
        ])->assertStatus(404);

        $this->assertSame(
            $wrongName->json('message'),
            $unknownNumber->json('message'),
            'A wrong name and an unknown number must answer identically.',
        );
        $this->assertSame($wrongName->json('code'), $unknownNumber->json('code'));
    }

    public function test_an_identifier_is_required(): void
    {
        $this->postJson(route('api.v1.attendee.find-my-ticket'), ['full_name' => 'Rahim Uddin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mobile', 'email']);
    }

    /**
     * A passwordless entry leaves no SMS and no password attempt behind, so
     * without this row there would be nothing anywhere to say a session had
     * been opened on the account.
     */
    public function test_opening_a_session_is_written_to_the_audit_trail(): void
    {
        [$attendee] = $this->openSession();

        $log = ActivityLog::where('event', 'attendee_lookup_session_opened')
            ->where('subject_id', $attendee->id)
            ->sole();

        $this->assertSame('mobile', $log->properties['identifier']);
        // The row records which identifier was used, never its value.
        $this->assertStringNotContainsString('8801711111111', json_encode($log->properties));
    }

    // ------------------------------------------------------------- the session

    public function test_the_session_reads_the_profile(): void
    {
        [$attendee, $token] = $this->openSession();

        $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.me.show'))
            ->assertStatus(200)
            ->assertJsonPath('data.ulid', $attendee->ulid)
            ->assertJsonPath('data.full_name', 'Rahim Uddin');
    }

    public function test_the_session_corrects_the_profile(): void
    {
        [$attendee, $token] = $this->openSession();

        $this->withToken($token)
            ->patchJson(route('api.v1.attendee.find-my-ticket.me.update'), [
                'father_name' => 'Abdul Karim',
                'occupation' => 'Teacher',
                'current_address' => 'Village Road, Nilphamari',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.father_name', 'Abdul Karim');

        $this->assertSame('Teacher', $attendee->refresh()->occupation);
    }

    /**
     * The email address is an identifier this lookup matches on *and* where
     * the ticket is delivered. A caller who guessed a name and could
     * repoint it would have turned a weak read into a resend of somebody
     * else's ticket to an inbox they control.
     */
    public function test_the_session_cannot_change_the_email_address(): void
    {
        [$attendee, $token] = $this->openSession();

        $this->withToken($token)
            ->patchJson(route('api.v1.attendee.find-my-ticket.me.update'), [
                'email' => 'attacker@example.com',
                'occupation' => 'Teacher',
            ])
            ->assertStatus(200);

        $attendee->refresh();
        $this->assertSame('rahim@example.com', $attendee->email);
        // The rest of the update still applied — the field is ignored, not
        // the whole request refused.
        $this->assertSame('Teacher', $attendee->occupation);
    }

    public function test_the_session_lists_registrations_and_ticket_numbers(): void
    {
        $attendee = $this->attendee();
        $ticket = $this->ticketedRegistration($attendee);

        $token = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
        ])->json('token');

        $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.registrations.index'))
            ->assertStatus(200)
            ->assertJsonPath('data.0.tickets.0.ticket_number', $ticket->ticket_number)
            ->assertJsonPath('data.0.tickets.0.status', 'active');
    }

    /**
     * The QR payload *is* admission. A guessed name must never buy it, and
     * `TicketResource` publishes it whenever the `qrCode` relation happens
     * to be loaded — so this asserts on the response body rather than on
     * the eager-load list, which is what would actually regress.
     */
    public function test_the_registrations_response_never_carries_the_qr_payload(): void
    {
        $attendee = $this->attendee();
        $ticket = $this->ticketedRegistration($attendee);

        $token = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
        ])->json('token');

        $body = $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.registrations.index'))
            ->assertStatus(200)
            ->getContent();

        $this->assertStringNotContainsString('qr_code_payload', (string) $body);
        $this->assertStringNotContainsString('qr_code_image_url', (string) $body);
        $this->assertStringNotContainsString('DTM1.'.$ticket->ulid, (string) $body);
    }

    public function test_a_session_only_ever_sees_its_own_attendee(): void
    {
        [, $token] = $this->openSession();

        $other = $this->attendee(['mobile' => '+8801722222222', 'email' => 'other@example.com']);
        $this->ticketedRegistration($other);

        $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.registrations.index'))
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    // ------------------------------------------------- what it must not reach

    /**
     * The whole safety argument in one assertion: the lookup token carries
     * `attendee-lookup` and every real self-service route demands
     * `attendee`, so the PDF, cancellation and the password endpoint are
     * all closed to it.
     */
    public function test_a_lookup_token_is_refused_by_every_signed_in_route(): void
    {
        $attendee = $this->attendee();
        $ticket = $this->ticketedRegistration($attendee);

        $registration = Registration::where('attendee_id', $attendee->id)->sole();

        $token = $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
        ])->json('token');

        $this->withToken($token)->getJson(route('api.v1.attendee.me.show'))->assertStatus(403);
        $this->withToken($token)->patchJson(route('api.v1.attendee.me.update'), ['occupation' => 'x'])->assertStatus(403);
        $this->withToken($token)->getJson(route('api.v1.attendee.registrations.index'))->assertStatus(403);
        $this->withToken($token)->getJson(route('api.v1.attendee.tickets.pdf', $ticket->ulid))->assertStatus(403);
        $this->withToken($token)->getJson(route('api.v1.attendee.tickets.show', $ticket->ulid))->assertStatus(403);
        $this->withToken($token)->postJson(route('api.v1.attendee.registrations.cancel', $registration->ulid))->assertStatus(403);
        $this->withToken($token)->postJson(route('api.v1.attendee.auth.password'), [
            'password' => 'a-new-password',
            'password_confirmation' => 'a-new-password',
        ])->assertStatus(403);
    }

    /**
     * The other direction: a full sign-in must keep working exactly as it
     * did. This feature is additive, and nothing about the stronger path
     * changed.
     */
    public function test_a_full_session_still_reaches_everything_it_did(): void
    {
        $attendee = $this->attendee();
        $attendee->forceFill(['password' => 'correct-horse-battery', 'password_set_at' => now()])->save();

        $token = $this->postJson(route('api.v1.attendee.auth.login'), [
            'mobile' => '01711111111',
            'password' => 'correct-horse-battery',
        ])->assertStatus(200)->json('token');

        $this->withToken($token)->getJson(route('api.v1.attendee.me.show'))->assertStatus(200);
        $this->withToken($token)->getJson(route('api.v1.attendee.registrations.index'))->assertStatus(200);
    }

    public function test_the_lookup_routes_refuse_an_ordinary_attendee_token(): void
    {
        $attendee = $this->attendee();
        $token = $attendee->createToken('attendee-session', ['attendee'], now()->addDay())->plainTextToken;

        // Not a weakness — the two abilities are simply disjoint, and the
        // signed-in dashboard has its own equivalents of these routes.
        $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.me.show'))
            ->assertStatus(403);
    }

    public function test_the_session_expires(): void
    {
        [, $token] = $this->openSession();

        $this->travel(61)->minutes();

        $this->withToken($token)
            ->getJson(route('api.v1.attendee.find-my-ticket.me.show'))
            ->assertStatus(401);
    }

    /**
     * The name is guessable in a way a password is not, so the limiter is
     * the control that actually bounds an attack here.
     */
    public function test_guessing_at_the_name_is_rate_limited_per_identifier(): void
    {
        $this->attendee();

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('api.v1.attendee.find-my-ticket'), [
                'mobile' => '01711111111',
                'full_name' => "Guess {$i}",
            ])->assertStatus(404);
        }

        $this->postJson(route('api.v1.attendee.find-my-ticket'), [
            'mobile' => '01711111111',
            'full_name' => 'Rahim Uddin',
        ])->assertStatus(429);
    }
}
