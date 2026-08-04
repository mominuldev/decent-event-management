<?php

namespace Tests\Feature\Attendee;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * docs/06 §6.4 file-serving rules, closed for tickets in Phase 6: private
 * disk, short-TTL signed URL, never a bare path.
 */
class TicketPdfDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_fetch_a_signed_url_and_download_the_pdf(): void
    {
        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);
        $ticket = app(IssueTicket::class)->execute($registration);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $response = $this->getJson(route('api.v1.attendee.tickets.pdf', ['ticket' => $ticket->ulid]));

        $response->assertStatus(200);
        $url = $response->json('url');
        $this->assertNotNull($url);
        $this->assertStringContainsString('signature=', $url);

        $download = $this->get($url);
        $download->assertStatus(200);
        $download->assertHeader('Content-Disposition');
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        $download->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_another_attendees_ticket_is_not_found(): void
    {
        $owner = Attendee::factory()->create();
        $intruder = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $owner->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);
        $ticket = app(IssueTicket::class)->execute($registration);

        Sanctum::actingAs($intruder, ['attendee'], 'attendee');

        $this->getJson(route('api.v1.attendee.tickets.pdf', ['ticket' => $ticket->ulid]))
            ->assertStatus(404);
    }

    public function test_pdf_not_yet_generated_returns_404(): void
    {
        $attendee = Attendee::factory()->create();
        $ticket = Ticket::factory()->create(['attendee_id' => $attendee->id, 'status' => 'active']);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');

        $this->getJson(route('api.v1.attendee.tickets.pdf', ['ticket' => $ticket->ulid]))
            ->assertStatus(404)
            ->assertJsonPath('code', 'NOT_FOUND');
    }

    public function test_a_tampered_signed_url_is_rejected(): void
    {
        $attendee = Attendee::factory()->create();
        $ticketType = TicketType::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);
        $ticket = app(IssueTicket::class)->execute($registration);

        Sanctum::actingAs($attendee, ['attendee'], 'attendee');
        $url = $this->getJson(route('api.v1.attendee.tickets.pdf', ['ticket' => $ticket->ulid]))->json('url');

        $this->get($url.'x')->assertStatus(403);
    }
}
