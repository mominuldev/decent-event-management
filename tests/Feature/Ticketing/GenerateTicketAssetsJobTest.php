<?php

namespace Tests\Feature\Ticketing;

use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\GenerateTicketPdf;
use App\Domain\Ticketing\Services\RenderTicketQrImage;
use App\Jobs\GenerateTicketAssetsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * docs/08 Phase 6 — the first job on the `tickets` Horizon lane. Runs
 * synchronously in tests (QUEUE_CONNECTION=sync), so issuing a ticket
 * exercises the whole pipeline end to end.
 */
class GenerateTicketAssetsJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_issuing_a_ticket_generates_a_real_qr_image_and_pdf(): void
    {
        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create(['full_name' => 'Test Holder']);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);

        $ticket = app(IssueTicket::class)->execute($registration);
        $ticket->refresh()->load(['qrCode.image', 'pdf']);

        $this->assertNotNull($ticket->pdf_media_id);
        $this->assertNotNull($ticket->qrCode->image_media_id);

        $this->assertSame('application/pdf', $ticket->pdf->mime_type);
        $this->assertGreaterThan(1000, $ticket->pdf->size_bytes);
        $this->assertFalse($ticket->pdf->is_public);
        $this->assertSame('local', $ticket->pdf->disk);

        $this->assertSame('image/png', $ticket->qrCode->image->mime_type);
        $this->assertSame(512, $ticket->qrCode->image->width);
        $this->assertFalse($ticket->qrCode->image->is_public);
    }

    public function test_rerunning_the_job_does_not_duplicate_media_rows(): void
    {
        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create();
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);

        $ticket = app(IssueTicket::class)->execute($registration);
        $ticket->refresh();
        $firstPdfMediaId = $ticket->pdf_media_id;

        (new GenerateTicketAssetsJob($ticket->id))->handle(
            app(RenderTicketQrImage::class),
            app(GenerateTicketPdf::class),
        );

        $ticket->refresh();
        $this->assertSame($firstPdfMediaId, $ticket->pdf_media_id);
        $this->assertSame(1, MediaFile::where('collection', 'ticket_pdf')
            ->where('id', $firstPdfMediaId)->count());
    }
}
