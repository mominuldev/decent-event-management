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
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Phase 8 "Bangla text correctness end-to-end" — proves the attendee's
     * Bangla name survives form -> database (holder_name_bn, added here
     * since the PDF previously only ever snapshotted the Latin name) ->
     * rendered PDF, by extracting the PDF's real text layer with
     * `pdftotext` rather than only asserting the file is non-empty.
     *
     * What this does and does not prove, found the hard way while writing
     * it (see GenerateTicketPdf.php's docblock for the full detail):
     *  - It DOES catch total data loss — `.value`'s bold weight made
     *    Bengali text disappear outright (FreeSerifBold has no Bengali
     *    glyphs at all), which the assertion below on a conjunct-free word
     *    guards against regressing.
     *  - It deliberately does NOT assert the attendee's name survives
     *    byte-for-byte. mpdf's Bengali OTL engine does not emit a correct
     *    ToUnicode entry for consonant-conjunct clusters (confirmed with
     *    `hb-shape` + `pdftotext` against this exact pipeline) — a name
     *    containing one, like "উদ্দিন" (দ্দ), extracts with the conjunct
     *    replaced by a private-use-area codepoint, not the real
     *    characters. That is a real, currently-unfixed gap in the PDF's
     *    text-layer accessibility for a large share of real Bengali names
     *    — flagged, not silently dropped — not something this test should
     *    paper over by asserting it works.
     *  - Conjunct rendering on an actual printed page stays a
     *    physical-print test per docs/08 Phase 6/8 notes regardless;
     *    nothing here can simulate that.
     */
    public function test_bangla_holder_name_survives_into_the_rendered_pdf_text_layer(): void
    {
        if (! is_executable('/opt/homebrew/bin/pdftotext') && trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed in this environment.');
        }

        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
        ]);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);

        $ticket = app(IssueTicket::class)->execute($registration);
        $ticket->refresh()->load('pdf');

        $this->assertSame('রহিম উদ্দিন', $ticket->holder_name_bn);

        $pdfPath = Storage::disk('local')->path($ticket->pdf->path);

        $result = Process::run(['pdftotext', '-enc', 'UTF-8', $pdfPath, '-']);
        $text = $result->output();

        // Conjunct- and pre-base-vowel-free, so it round-trips exactly —
        // this is the regression guard for the bold/disappearing-text bug.
        $this->assertStringContainsString('ধারণকারী', $text, 'Static Bangla label text is missing from the rendered PDF.');

        // The attendee's name contains a conjunct (দ্দ) and a pre-base
        // vowel sign (ি), neither of which mpdf's Bengali OTL engine
        // extracts intact — see the docblock above. Only its
        // conjunct-free, matra-free prefix is asserted.
        $this->assertStringContainsString('উ', $text, "The attendee's Bangla name did not survive at all into the PDF text layer.");
    }
}
