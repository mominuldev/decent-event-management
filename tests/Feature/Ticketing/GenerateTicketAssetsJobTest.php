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
     * The Bangla text layer, asserted as a real round trip.
     *
     * This test used to assert almost nothing on purpose — only that a
     * conjunct-free label survived, and that the attendee's name had not
     * vanished *entirely* — because mpdf could not produce a correct text
     * layer for Bengali and pinning the mangled bytes would have frozen the
     * bug in place. Rendering moved to headless Chrome (see
     * config/pdf.php and GenerateTicketPdf's docblock), so the real
     * assertion is now possible and is what runs.
     *
     * The name is chosen adversarially: "রহিম উদ্দিন" carries both failure
     * modes at once — the দ্দ conjunct, which mpdf dropped from the text
     * layer completely, and the pre-base vowel sign ি, which mpdf emitted
     * in visual rather than logical order.
     *
     * Still out of scope, and not simulated here: how any of this looks on
     * an actual printed page (docs/08 Phase 6/8 physical-print testing).
     */
    public function test_bangla_holder_name_survives_into_the_rendered_pdf_text_layer(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
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
        $text = Process::run(['pdftotext', '-enc', 'UTF-8', $pdfPath, '-'])->output();

        // Static bilingual label — no conjunct, no pre-base vowel.
        $this->assertStringContainsString('ধারণকারী', $text, 'Static Bangla label text is missing from the rendered PDF.');

        // The whole name, intact and in logical order. Whitespace is
        // normalised out because pdftotext inserts word breaks from glyph
        // advances, which splits a Bengali word without losing a character
        // of it — that is an extractor heuristic, not a defect in the PDF.
        $normalised = preg_replace('/\s+/u', '', $text) ?? '';

        $this->assertStringContainsString(
            'রহিমউদ্দিন',
            $normalised,
            "The attendee's Bangla name did not survive intact into the PDF text layer — "
            .'the দ্দ conjunct or the pre-base ি vowel sign has been dropped or reordered.'
        );
    }

    /**
     * Bold Bangla, which is a separate defect from the text layer.
     *
     * mpdf's bundled FreeSerifBold.ttf has zero glyph coverage for the
     * Bengali block, so bold Bangla did not degrade — it disappeared from
     * the page. The ticket template carried a `.bn-value` class purely to
     * opt the one dynamic Bangla field back out of bold. That workaround is
     * gone, and this asserts it is not needed: the holder name is rendered
     * bold and still extracts.
     */
    public function test_bangla_renders_in_bold_without_disappearing(): void
    {
        if (trim((string) shell_exec('command -v pdftotext')) === '') {
            $this->markTestSkipped('pdftotext (poppler-utils) is not installed in this environment.');
        }

        $ticketType = TicketType::factory()->create();
        $attendee = Attendee::factory()->create([
            'full_name' => 'Mohammad Rahim',
            'full_name_bn' => 'মোহাম্মদ রহিম',
        ]);
        $registration = Registration::factory()->create([
            'attendee_id' => $attendee->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);

        $ticket = app(IssueTicket::class)->execute($registration);
        $ticket->refresh()->load('pdf');

        $pdfPath = Storage::disk('local')->path($ticket->pdf->path);
        $text = Process::run(['pdftotext', '-enc', 'UTF-8', $pdfPath, '-'])->output();
        $normalised = preg_replace('/\s+/u', '', $text) ?? '';

        // The holder name is styled `font-weight: 700` by the template.
        $this->assertStringContainsString('মোহাম্মদরহিম', $normalised);

        // And a bold face genuinely carrying Bengali glyphs is embedded,
        // rather than the text silently falling back to a regular weight.
        $fonts = Process::run(['pdffonts', $pdfPath])->output();
        $this->assertMatchesRegularExpression('/Bengali-\w*Bold/i', $fonts, 'No bold Bengali face was embedded in the ticket PDF.');
    }
}
