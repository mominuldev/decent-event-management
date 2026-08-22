<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Channels\MailDriver;
use App\Domain\Notification\Models\Notification;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Shared\Models\EventSetting;
use App\Domain\Shared\Models\MediaFile;
use App\Domain\Ticketing\Models\QrCode;
use App\Domain\Ticketing\Models\Ticket;
use App\Domain\Ticketing\Services\RenderTicketQrImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;
use Tests\TestCase;

/**
 * The confirmation email a ticket-holder is admitted with.
 *
 * These go through the real mailer on the `array` transport rather than
 * `Mail::fake()`, deliberately: what matters here is the MIME the
 * provider would receive — that the QR travels as an inline part rather
 * than a remote image a client would block or a signed URL that expires —
 * and a faked mailer never builds one.
 */
class TicketEmailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mail.default' => 'array']);
    }

    public function test_the_qr_code_travels_as_an_inline_image_part(): void
    {
        $ticket = $this->ticketWithQr();

        $email = $this->sendFor($ticket);

        $html = (string) $email->getHtmlBody();
        $inline = $this->inlineImages($email);

        $this->assertCount(1, $inline, 'the ticket email should carry exactly one QR part');
        $this->assertSame('image/png', $inline[0]->getMediaType().'/'.$inline[0]->getMediaSubtype());

        // The <img> must point at the part that actually travelled, not at
        // a data: URI (Gmail and Outlook drop those) or a signed URL (they
        // expire in 15 minutes and this email outlives that by months).
        $this->assertStringContainsString('cid:'.$inline[0]->getContentId(), $html);
        $this->assertStringNotContainsString('src="data:', $html);
    }

    public function test_the_embedded_image_is_the_ticket_own_signed_qr(): void
    {
        $ticket = $this->ticketWithQr();

        $email = $this->sendFor($ticket);

        $expected = app(RenderTicketQrImage::class)->render($ticket->qrCode->payload);

        $this->assertSame($expected, $this->inlineImages($email)[0]->getBody());
    }

    public function test_the_qr_is_rendered_from_the_payload_when_the_asset_job_has_not_run_yet(): void
    {
        // The real ordering on a live system: `TicketIssued` queues this
        // email on the `notifications` lane and the QR PNG on `tickets`, so
        // the stored image is usually still missing when the email drains.
        $ticket = $this->ticketWithQr();
        $this->assertNull($ticket->qrCode->image_media_id);

        $email = $this->sendFor($ticket);

        $this->assertCount(1, $this->inlineImages($email));
    }

    public function test_the_stored_image_is_reused_once_the_asset_job_has_produced_one(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('ticket_qr/stored.png', 'stored-png-bytes');

        $ticket = $this->ticketWithQr();
        $media = MediaFile::create([
            'collection' => 'ticket_qr',
            'disk' => 'local',
            'path' => 'ticket_qr/stored.png',
            'original_name' => 'qr.png',
            'mime_type' => 'image/png',
            'extension' => 'png',
            'size_bytes' => 16,
            'checksum_sha256' => hash('sha256', 'stored-png-bytes'),
            'is_public' => false,
            'scan_status' => 'clean',
            'uploaded_by_type' => 'system',
        ]);
        $ticket->qrCode->forceFill(['image_media_id' => $media->id])->save();

        $email = $this->sendFor($ticket->fresh());

        $this->assertSame('stored-png-bytes', $this->inlineImages($email)[0]->getBody());
    }

    public function test_the_gate_details_are_rendered_by_the_shell_not_the_editable_template(): void
    {
        EventSetting::query()->updateOrCreate(
            ['key' => 'event.name'],
            ['group' => 'event', 'value' => 'School 100 Years Celebration', 'type' => 'string', 'is_public' => true, 'label' => 'Event name'],
        );

        $ticket = $this->ticketWithQr([
            'holder_name' => 'Rahim Uddin',
            'holder_name_bn' => 'রহিম উদ্দিন',
            'admits_total' => 3,
        ]);

        // A template body that mentions none of it — the facts below come
        // from the ticket, so an edited template cannot drop them.
        $html = (string) $this->sendFor($ticket, '<p>স্বাগতম।</p>')->getHtmlBody();

        $this->assertStringContainsString('School 100 Years Celebration', $html);
        $this->assertStringContainsString($ticket->ticket_number, $html);
        $this->assertStringContainsString('রহিম উদ্দিন', $html);
        $this->assertStringContainsString('৩ জন', $html);
        $this->assertStringContainsString('স্বাগতম।', $html);
    }

    public function test_the_shell_speaks_the_notification_own_language(): void
    {
        $ticket = $this->ticketWithQr(['holder_name' => 'Rahim Uddin', 'holder_name_bn' => 'রহিম উদ্দিন']);

        $bangla = (string) $this->sendFor($ticket)->getHtmlBody();

        $this->assertStringContainsString('প্রবেশপত্র', $bangla);
        $this->assertStringContainsString('গেটে স্ক্যান করুন', $bangla);
        $this->assertStringContainsString('আপনার নিবন্ধন দেখুন', $bangla);
        $this->assertStringNotContainsString('Scan at the gate', $bangla);

        // The same ticket in English: the chrome follows the row's locale,
        // so nothing here is hardcoded to either language.
        $english = (string) $this->sendFor($ticket->fresh(), locale: 'en')->getHtmlBody();

        $this->assertStringContainsString('Scan at the gate', $english);
        $this->assertStringContainsString('Rahim Uddin', $english);
        $this->assertStringNotContainsString('গেটে স্ক্যান করুন', $english);
    }

    public function test_a_ticket_with_no_bangla_snapshot_falls_back_to_the_attendee_record(): void
    {
        // `tickets.holder_name_bn` did not exist until Phase 8, so every
        // ticket issued before it has none — and printing the Latin name in
        // a Bangla message when the attendee record has a Bangla one is
        // giving up a step early.
        $attendee = Attendee::factory()->create(['full_name' => 'Rahim Uddin', 'full_name_bn' => 'রহিম উদ্দিন']);
        $ticket = $this->ticketWithQr([
            'attendee_id' => $attendee->id,
            'holder_name' => 'Rahim Uddin',
            'holder_name_bn' => null,
        ]);

        $this->assertStringContainsString('রহিম উদ্দিন', (string) $this->sendFor($ticket)->getHtmlBody());
    }

    public function test_the_snapshot_wins_over_the_attendee_record_when_it_exists(): void
    {
        // The snapshot is what the printed ticket and the gate list say. An
        // email that disagrees with the paper in someone's hand is worse
        // than one carrying a spelling they have since corrected.
        $attendee = Attendee::factory()->create(['full_name_bn' => 'নতুন নাম']);
        $ticket = $this->ticketWithQr([
            'attendee_id' => $attendee->id,
            'holder_name_bn' => 'পুরোনো নাম',
        ]);

        $html = (string) $this->sendFor($ticket)->getHtmlBody();

        $this->assertStringContainsString('পুরোনো নাম', $html);
        $this->assertStringNotContainsString('নতুন নাম', $html);
    }

    public function test_bangla_renders_its_own_numerals_except_in_the_ticket_number(): void
    {
        $ticket = $this->ticketWithQr([
            'ticket_number' => 'DEC100-CEN-2005-00042',
            'admits_total' => 4,
            'holder_batch_year' => 1993,
        ]);

        $html = (string) $this->sendFor($ticket)->getHtmlBody();

        $this->assertStringContainsString('৪ জন', $html);
        $this->assertStringContainsString('১৯৯৩', $html);

        // The number is quoted down a phone, typed into the admin console and
        // matched against a printed page — it stays Latin.
        $this->assertStringContainsString('DEC100-CEN-2005-00042', $html);
    }

    public function test_a_ticket_with_no_qr_row_sends_without_an_empty_panel(): void
    {
        $ticket = Ticket::factory()->create();

        $email = $this->sendFor($ticket);
        $html = (string) $email->getHtmlBody();

        $this->assertCount(0, $this->inlineImages($email));
        $this->assertStringNotContainsString('গেটে স্ক্যান করুন', $html);

        // The number still shows: it is the reference a holder quotes when
        // they call about a ticket whose code never rendered.
        $this->assertStringContainsString($ticket->ticket_number, $html);
    }

    public function test_only_the_icons_the_layout_asks_for_travel_with_the_message(): void
    {
        $ticket = $this->ticketWithQr();

        $names = $this->inlineIconNames($this->sendFor($ticket));

        // One part per distinct glyph — the ticket glyph appears in both the
        // masthead mark and the scan note, and must not be embedded twice.
        $this->assertSame($names, array_values(array_unique($names)));
        $this->assertContains('ticket.png', $names);
        $this->assertContains('mark.png', $names);

        // No venue is configured in this fixture, so the venue row is not
        // rendered — and its glyph must not travel either.
        $this->assertNotContains('pin.png', $names);
    }

    public function test_a_non_ticket_notification_still_gets_the_shell_without_a_qr(): void
    {
        $attendee = Attendee::factory()->create();

        $notification = Notification::factory()->create([
            'notifiable_type' => 'attendee',
            'notifiable_id' => $attendee->id,
            'channel' => 'email',
            'recipient' => 'attendee@example.com',
            'locale' => 'bn',
            'subject' => 'আপনার নিবন্ধন পেয়েছি',
            'body_rendered' => '<p>আপনার নিবন্ধনের জন্য ধন্যবাদ।</p>',
        ]);

        $result = (new MailDriver)->send($notification);
        $this->assertTrue($result->isSent());

        $email = $this->lastEmail();
        $html = (string) $email->getHtmlBody();

        $this->assertCount(0, $this->inlineImages($email));
        $this->assertCount(0, $this->inlineIconNames($email), 'a notifiable with no presentation embeds nothing');
        $this->assertStringContainsString('আপনার নিবন্ধনের জন্য ধন্যবাদ।', $html);
        $this->assertStringContainsString('এই ঠিকানায় উত্তর দেবেন না', $html);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticketWithQr(array $attributes = []): Ticket
    {
        $ticket = Ticket::factory()->create($attributes);
        QrCode::factory()->create(['ticket_id' => $ticket->id]);

        return $ticket->fresh();
    }

    private function sendFor(Ticket $ticket, string $body = '<p>আপনার টিকিট নিশ্চিত হয়েছে।</p>', string $locale = 'bn'): Email
    {
        $notification = Notification::factory()->create([
            'notifiable_type' => 'ticket',
            'notifiable_id' => $ticket->id,
            'template_key' => 'ticket_delivered',
            'channel' => 'email',
            'locale' => $locale,
            'recipient' => 'holder@example.com',
            'subject' => 'আপনার টিকিট প্রস্তুত — '.$ticket->ticket_number,
            'body_rendered' => $body,
        ]);

        $result = (new MailDriver)->send($notification);

        $this->assertTrue($result->isSent(), (string) $result->errorMessage);

        return $this->lastEmail();
    }

    private function lastEmail(): Email
    {
        $messages = Mail::getSymfonyTransport()->messages();

        $this->assertNotEmpty($messages, 'no message reached the array transport');

        $email = $messages->last()->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $email);

        return $email;
    }

    /**
     * @return array<int, DataPart>
     */
    private function inlineImages(Email $email): array
    {
        return array_values(array_filter(
            $email->getAttachments(),
            fn ($part) => $part->getFilename() === 'ticket-qr.png',
        ));
    }

    /**
     * @return array<int, string>
     */
    private function inlineIconNames(Email $email): array
    {
        return array_values(array_map(
            fn ($part) => (string) $part->getFilename(),
            array_filter(
                $email->getAttachments(),
                fn ($part) => $part->getMediaType() === 'image' && $part->getFilename() !== 'ticket-qr.png',
            ),
        ));
    }
}
