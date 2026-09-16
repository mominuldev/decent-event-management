<?php

namespace Tests\Feature\Ticketing;

use App\Domain\CheckIn\Models\EventSession;
use App\Domain\Notification\Channels\MailDriver;
use App\Domain\Notification\Models\Notification;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Exceptions\ImageRenderingException;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Shared\Services\HtmlToImageRenderer;
use App\Domain\Ticketing\Actions\IssueTicket;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Models\TicketType;
use App\Domain\Ticketing\Services\TicketShareCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\Support\FakeImageRenderer;
use Tests\TestCase;

/**
 * The "আমি থাকছি!" share card that goes out with a ticket confirmation.
 *
 * Everything here runs against {@see FakeImageRenderer} except the one
 * test that reads the pixels: what the card *says* is asserted on the
 * HTML the renderer is handed, which needs no Chrome, and the storage,
 * race and email plumbing behave identically on a 4×4 stand-in.
 */
class TicketShareCardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
        Storage::fake('local');
    }

    public function test_issuing_a_ticket_stores_the_card_as_a_private_jpeg(): void
    {
        $ticket = $this->issue();

        $ticket->refresh()->load('shareImage');

        $this->assertNotNull($ticket->share_image_media_id);
        $this->assertSame('image/jpeg', $ticket->shareImage->mime_type);
        $this->assertSame(TicketShareCard::COLLECTION, $ticket->shareImage->collection);
        $this->assertFalse($ticket->shareImage->is_public);
        $this->assertSame("ami-thakchi-{$ticket->ticket_number}.jpg", $ticket->shareImage->original_name);
        Storage::disk('local')->assertExists($ticket->shareImage->path);

        // Really a JPEG, not the PNG Chrome produced.
        $bytes = (string) Storage::disk('local')->get($ticket->shareImage->path);
        $this->assertSame("\xFF\xD8\xFF", substr($bytes, 0, 3));
    }

    public function test_the_card_carries_the_holder_name_batch_party_and_registration_number(): void
    {
        $ticket = $this->issue(
            attendee: ['full_name' => 'Rahim Uddin', 'full_name_bn' => 'মোহাম্মদ রহিম উদ্দিন', 'ssc_batch_year' => 1998, 'participant_type' => 'former_student'],
            registration: ['adults_count' => 2, 'children_count' => 1],
        );

        $html = app(TicketShareCard::class)->html($ticket);

        $this->assertStringContainsString('মোহাম্মদ রহিম উদ্দিন', $html);
        $this->assertStringContainsString('প্রাক্তন শিক্ষার্থী, এসএসসি ব্যাচ ১৯৯৮। পরিবারসহ ৩ জন।', $html);
        $this->assertStringContainsString('৩ জনের প্রবেশ', $html);
        $this->assertStringContainsString($ticket->registration->registration_number, $html);
        // The batch on the stub, in Bangla numerals.
        $this->assertStringContainsString('>১৯৯৮<', $html);
        $this->assertStringContainsString('আমি থাকছি!', $html);
    }

    public function test_a_party_of_one_is_not_described_as_a_family(): void
    {
        $ticket = $this->issue(
            attendee: ['ssc_batch_year' => 2005, 'participant_type' => 'former_student'],
            registration: ['adults_count' => 1, 'children_count' => 0],
        );

        $html = app(TicketShareCard::class)->html($ticket);

        $this->assertStringContainsString('প্রাক্তন শিক্ষার্থী, এসএসসি ব্যাচ ২০০৫।', $html);
        $this->assertStringNotContainsString('পরিবারসহ', $html);
        $this->assertStringContainsString('১ জনের প্রবেশ', $html);
    }

    public function test_a_holder_with_no_batch_year_shows_who_they_are_on_the_stub_instead(): void
    {
        $ticket = $this->issue(
            attendee: ['ssc_batch_year' => null, 'participant_type' => 'teacher'],
        );

        $html = app(TicketShareCard::class)->html($ticket);

        $this->assertStringNotContainsString('এসএসসি ব্যাচ', $html);
        $this->assertStringContainsString('পরিচয়', $html);
        $this->assertStringContainsString('শিক্ষক।', $html);
        $this->assertMatchesRegularExpression('/class="year long">\s*শিক্ষক\s*</u', $html);
    }

    public function test_the_session_supplies_the_date_time_and_venue(): void
    {
        config(['app.timezone' => 'Asia/Dhaka']);

        $session = EventSession::factory()->create([
            'starts_at' => '2027-02-12 02:00:00', // 08:00 in Asia/Dhaka
            'ends_at' => '2027-02-12 16:00:00',   // 22:00
            'venue' => 'বিদ্যালয় প্রাঙ্গণ',
        ]);
        $ticket = $this->issue(ticket: ['event_session_id' => $session->id]);

        $html = app(TicketShareCard::class)->html($ticket);

        // Carbon spells the month with a decomposed য় (য + ়), so the
        // expected string is taken from Carbon rather than typed.
        $month = Carbon::parse('2027-02-12')->locale('bn')->isoFormat('MMMM');
        $this->assertStringContainsString("১২ {$month} ২০২৭", $html);
        $this->assertStringContainsString('শুক্রবার', $html);
        $this->assertStringContainsString('সকাল ৮:০০', $html);
        $this->assertStringContainsString('রাত ১০:০০ পর্যন্ত', $html);
        $this->assertStringContainsString('বিদ্যালয় প্রাঙ্গণ', $html);
    }

    public function test_the_same_row_is_reused_and_a_second_render_never_happens(): void
    {
        $ticket = $this->issue();
        $ticket->refresh();
        $first = $ticket->share_image_media_id;

        /** @var FakeImageRenderer $renderer */
        $renderer = app(HtmlToImageRenderer::class);
        $rendersSoFar = count($renderer->rendered);

        $media = app(TicketShareCard::class)->ensureStored($ticket->fresh());

        $this->assertSame($first, $media->id);
        $this->assertCount($rendersSoFar, $renderer->rendered, 'a stored card must not be drawn again');
        $this->assertSame(1, MediaFile::where('collection', TicketShareCard::COLLECTION)->count());
    }

    public function test_the_confirmation_email_carries_the_card_as_an_inline_jpeg_part(): void
    {
        $ticket = $this->issue();

        $email = $this->sendFor($ticket);
        $inline = $this->inlineImages($email);
        $html = (string) $email->getHtmlBody();

        $jpegs = array_values(array_filter($inline, fn (DataPart $part) => $part->getMediaSubtype() === 'jpeg'));
        $this->assertCount(1, $jpegs, 'the share card should travel exactly once');
        $this->assertStringContainsString('cid:'.$jpegs[0]->getContentId(), $html);
        $this->assertStringContainsString('বন্ধুদের জানান', $html);

        // The QR still travels beside it — the card does not replace the ticket.
        $pngs = array_filter($inline, fn (DataPart $part) => $part->getMediaSubtype() === 'png');
        $this->assertNotEmpty($pngs);
    }

    public function test_a_card_that_cannot_be_drawn_does_not_stop_the_email(): void
    {
        // A ticket whose card was never stored — the real ordering when the
        // email drains before the asset job — on a host where Chrome fails.
        $ticket = $this->issue();
        $ticket->refresh();
        $ticket->forceFill(['share_image_media_id' => null])->save();

        $this->app->instance(HtmlToImageRenderer::class, new class extends HtmlToImageRenderer
        {
            public function renderPng(string $html, int $width, int $height, int $scale = 2): string
            {
                throw ImageRenderingException::failed('no chromium on this host');
            }
        });

        $email = $this->sendFor($ticket->fresh());

        $jpegs = array_filter($this->inlineImages($email), fn (DataPart $part) => $part->getMediaSubtype() === 'jpeg');
        $this->assertCount(0, $jpegs);
        $this->assertStringNotContainsString('বন্ধুদের জানান', (string) $email->getHtmlBody());
        // And the QR — the part that admits — is still there.
        $this->assertNotEmpty($this->inlineImages($email));
    }

    public function test_a_voided_ticket_email_does_not_say_i_am_in(): void
    {
        $ticket = $this->issue();
        $ticket->refresh();
        $ticket->transitionTo('voided');

        $email = $this->sendFor($ticket->fresh());

        $jpegs = array_filter($this->inlineImages($email), fn (DataPart $part) => $part->getMediaSubtype() === 'jpeg');
        $this->assertCount(0, $jpegs);
    }

    public function test_the_attendee_ticket_endpoint_exposes_a_signed_url_for_the_card(): void
    {
        $ticket = $this->issue();

        Sanctum::actingAs($ticket->attendee, ['attendee'], 'attendee');

        $response = $this->getJson("/api/v1/attendee/tickets/{$ticket->ulid}");

        $response->assertOk();
        $url = $response->json('data.share_image_url');
        $this->assertIsString($url);
        $this->assertStringContainsString('/api/v1/media/', $url);
        $this->assertStringContainsString('signature=', $url);
    }

    /**
     * @param  array<string, mixed>  $attendee
     * @param  array<string, mixed>  $registration
     * @param  array<string, mixed>  $ticket
     */
    private function issue(array $attendee = [], array $registration = [], array $ticket = []): Ticket
    {
        $ticketType = TicketType::factory()->create();
        $attendeeModel = Attendee::factory()->create($attendee + ['email' => 'holder@example.test']);
        $registrationModel = Registration::factory()->create($registration + [
            'attendee_id' => $attendeeModel->id,
            'ticket_type_id' => $ticketType->id,
            'status' => 'paid',
        ]);

        $issued = app(IssueTicket::class)->execute($registrationModel);

        if ($ticket !== []) {
            $issued->forceFill($ticket)->save();
        }

        return $issued->fresh();
    }

    private function sendFor(Ticket $ticket): Email
    {
        $notification = Notification::factory()->create([
            'notifiable_type' => 'ticket',
            'notifiable_id' => $ticket->id,
            'template_key' => 'ticket_delivered',
            'channel' => 'email',
            'locale' => 'bn',
            'recipient' => 'holder@example.test',
            'subject' => 'আপনার টিকিট প্রস্তুত — '.$ticket->ticket_number,
            'body_rendered' => '<p>আপনার টিকিট নিশ্চিত হয়েছে।</p>',
        ]);

        $result = (new MailDriver)->send($notification);

        $this->assertTrue($result->isSent(), (string) $result->errorMessage);

        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'no message reached the array transport');

        $email = $messages->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * @return list<DataPart>
     */
    private function inlineImages(Email $email): array
    {
        return array_values(array_filter(
            $email->getAttachments(),
            fn (DataPart $part) => $part->getMediaType() === 'image',
        ));
    }
}
