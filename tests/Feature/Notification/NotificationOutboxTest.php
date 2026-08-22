<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationTemplate;
use App\Domain\Payment\Events\ManualPaymentVerified;
use App\Domain\Payment\Events\PaymentFailed;
use App\Domain\Payment\Events\PaymentSucceeded;
use App\Domain\Payment\Events\RefundIssued;
use App\Domain\Payment\Models\Payment;
use App\Domain\Payment\Models\Refund;
use App\Domain\Registration\Events\RegistrationCreated;
use App\Domain\Registration\Models\Attendee;
use App\Domain\Registration\Models\Registration;
use App\Domain\Shared\Models\User;
use App\Domain\Ticketing\Events\TicketIssued;
use App\Domain\Ticketing\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Docs/01 §1.6 — one outbox row per active (template_key, channel), never
 * a duplicate for the same (notifiable, template_key, channel).
 */
class NotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    private function activeTemplate(string $key, string $channel = 'email', string $locale = 'en'): NotificationTemplate
    {
        return NotificationTemplate::factory()->create([
            'key' => $key,
            'channel' => $channel,
            'locale' => $locale,
            'version' => 1,
            'subject' => $locale.' subject {{full_name}}',
            'body' => $locale.' body {{full_name}}',
            'is_active' => true,
        ]);
    }

    public function test_a_bangla_message_greets_the_reader_by_their_bangla_name(): void
    {
        config(['notifications.locales.default' => 'bn']);

        NotificationTemplate::factory()->create([
            'key' => 'registration_received', 'channel' => 'email', 'locale' => 'bn', 'version' => 1,
            'subject' => 'x', 'body' => 'প্রিয় {{full_name_bn}}', 'is_active' => true,
        ]);

        $attendee = Attendee::factory()->create([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => 'রহিম উদ্দিন',
            'email' => 'rahim@example.com',
        ]);

        RegistrationCreated::dispatch(Registration::factory()->for($attendee)->create());

        $this->assertSame('প্রিয় রহিম উদ্দিন', Notification::firstOrFail()->body_rendered);
    }

    public function test_a_reader_with_no_bangla_name_is_greeted_in_latin_rather_than_not_at_all(): void
    {
        config(['notifications.locales.default' => 'bn']);

        NotificationTemplate::factory()->create([
            'key' => 'registration_received', 'channel' => 'email', 'locale' => 'bn', 'version' => 1,
            'subject' => 'x', 'body' => 'প্রিয় {{full_name_bn}}', 'is_active' => true,
        ]);

        // Rows created before the public form required a Bangla name, and
        // anything an admin or an import creates today, may not have one.
        $attendee = Attendee::factory()->create([
            'full_name' => 'Rahim Uddin',
            'full_name_bn' => null,
            'email' => 'rahim@example.com',
        ]);

        RegistrationCreated::dispatch(Registration::factory()->for($attendee)->create());

        $this->assertSame('প্রিয় Rahim Uddin', Notification::firstOrFail()->body_rendered);
    }

    public function test_a_notification_is_written_in_the_language_the_config_names(): void
    {
        config(['notifications.locales.default' => 'bn']);

        $this->activeTemplate('registration_received', 'email', 'en');
        $this->activeTemplate('registration_received', 'email', 'bn');

        RegistrationCreated::dispatch(Registration::factory()->for(
            Attendee::factory()->create(['email' => 'jane@example.com'])
        )->create());

        $notification = Notification::where('template_key', 'registration_received')->firstOrFail();

        $this->assertSame('bn', $notification->locale);
        $this->assertStringStartsWith('bn body', (string) $notification->body_rendered);
    }

    public function test_a_channel_may_be_written_in_a_different_language_from_the_default(): void
    {
        // Bangla SMS costs two to three times the segments of GSM-7, so the
        // per-channel override in config/notifications.php is a real lever.
        //
        // Driven off `payment_failed` rather than `registration_received`:
        // booking no longer sends SMS at all (see the test below), and this
        // needs an event that still puts a row on both channels.
        config(['notifications.locales.default' => 'bn', 'notifications.locales.sms' => 'en']);

        foreach (['email', 'sms'] as $channel) {
            $this->activeTemplate('payment_failed', $channel, 'en');
            $this->activeTemplate('payment_failed', $channel, 'bn');
        }

        $attendee = Attendee::factory()->create(['email' => 'jane@example.com', 'mobile' => '8801711111111']);
        $registration = Registration::factory()->for($attendee)->create();

        PaymentFailed::dispatch(
            Payment::factory()->for($registration)->for($attendee)->create(),
        );

        $this->assertSame('bn', Notification::where('channel', 'email')->firstOrFail()->locale);
        $this->assertSame('en', Notification::where('channel', 'sms')->firstOrFail()->locale);
    }

    public function test_a_missing_translation_falls_back_instead_of_dropping_the_message(): void
    {
        // No row at all is the failure mode this guards: an untranslated
        // (key, channel) pair would otherwise take that notification off the
        // air silently, with nothing in the delivery log to show for it.
        config(['notifications.locales.default' => 'bn', 'notifications.fallback_locale' => 'en']);

        $this->activeTemplate('registration_received', 'email', 'en');

        RegistrationCreated::dispatch(Registration::factory()->for(
            Attendee::factory()->create(['email' => 'jane@example.com'])
        )->create());

        $notification = Notification::where('template_key', 'registration_received')->firstOrFail();

        // The row records what was actually rendered, so a resend reproduces
        // this message rather than hunting for the missing translation.
        $this->assertSame('en', $notification->locale);
        $this->assertStringStartsWith('en body', (string) $notification->body_rendered);
    }

    public function test_booking_notifies_by_email_and_whatsapp_but_never_by_sms(): void
    {
        foreach (['email', 'sms', 'whatsapp'] as $channel) {
            $this->activeTemplate('registration_received', $channel);
        }

        $attendee = Attendee::factory()->create(['full_name' => 'Jane Doe', 'email' => 'jane@example.com', 'mobile' => '8801711111111']);
        $registration = Registration::factory()->for($attendee)->create();

        RegistrationCreated::dispatch($registration);

        // Two, not three, and an active SMS template is deliberately present
        // to prove the channel list is what excludes it rather than a
        // missing row. A ticket purchase sends exactly one SMS — the ticket
        // confirmation — where it used to send three; booking and payment
        // are email-only now, which is two thirds of the SMS bill per sale.
        $this->assertSame(
            ['email', 'whatsapp'],
            Notification::where('template_key', 'registration_received')->orderBy('channel')->pluck('channel')->all(),
        );

        $emailNotification = Notification::where('template_key', 'registration_received')->where('channel', 'email')->firstOrFail();
        $this->assertSame('en subject Jane Doe', $emailNotification->subject);
        $this->assertSame('en body Jane Doe', $emailNotification->body_rendered);
        $this->assertSame('jane@example.com', $emailNotification->recipient);
        $this->assertSame('registration', $emailNotification->notifiable_type);
        $this->assertSame($registration->id, $emailNotification->notifiable_id);
    }

    public function test_duplicate_event_dispatch_does_not_double_queue(): void
    {
        $this->activeTemplate('registration_received', 'email');

        $attendee = Attendee::factory()->create(['email' => 'jane@example.com']);
        $registration = Registration::factory()->for($attendee)->create();

        RegistrationCreated::dispatch($registration);
        RegistrationCreated::dispatch($registration);

        $this->assertSame(1, Notification::where('template_key', 'registration_received')->count());
    }

    public function test_channel_skipped_when_no_active_template_exists(): void
    {
        $this->activeTemplate('registration_received', 'email');
        // No sms/whatsapp template seeded.

        $attendee = Attendee::factory()->create(['email' => 'jane@example.com']);
        $registration = Registration::factory()->for($attendee)->create();

        RegistrationCreated::dispatch($registration);

        $this->assertSame(1, Notification::count());
        $this->assertSame('email', Notification::first()->channel);
    }

    public function test_channel_skipped_when_recipient_is_missing(): void
    {
        $this->activeTemplate('registration_received', 'email');

        $attendee = Attendee::factory()->create(['email' => null]);
        $registration = Registration::factory()->for($attendee)->create();

        RegistrationCreated::dispatch($registration);

        $this->assertSame(0, Notification::count());
    }

    public function test_payment_succeeded_queues_notification(): void
    {
        $this->activeTemplate('payment_succeeded', 'email');

        $attendee = Attendee::factory()->create(['email' => 'payer@example.com']);
        // 'paid' is the real precondition VerifyPayment::markSucceeded()
        // guarantees before dispatching PaymentSucceeded — the global
        // IssueTicketForSucceededPayment listener also reacts to this
        // event and would reject an illegal paid->confirmed transition
        // otherwise.
        $registration = Registration::factory()->for($attendee)->create(['status' => 'paid']);
        $payment = Payment::factory()->for($attendee)->for($registration)->create();

        PaymentSucceeded::dispatch($payment);

        $this->assertSame(1, Notification::where('template_key', 'payment_succeeded')->where('notifiable_type', 'payment')->count());
    }

    public function test_payment_failed_queues_email_and_sms_but_not_whatsapp(): void
    {
        foreach (['email', 'sms', 'whatsapp'] as $channel) {
            $this->activeTemplate('payment_failed', $channel);
        }

        $attendee = Attendee::factory()->create(['email' => 'payer@example.com', 'mobile' => '8801711111111']);
        $payment = Payment::factory()->for($attendee)->create();

        PaymentFailed::dispatch($payment);

        $channels = Notification::where('template_key', 'payment_failed')->pluck('channel')->all();
        $this->assertEqualsCanonicalizing(['email', 'sms'], $channels);
    }

    public function test_manual_payment_verified_queues_notification(): void
    {
        $this->activeTemplate('payment_manual_verified', 'email');

        $attendee = Attendee::factory()->create(['email' => 'payer@example.com']);
        $payment = Payment::factory()->for($attendee)->create();
        $verifier = User::factory()->create();

        ManualPaymentVerified::dispatch($payment, $verifier);

        $this->assertSame(1, Notification::where('template_key', 'payment_manual_verified')->count());
    }

    public function test_refund_issued_queues_email_and_sms_but_not_whatsapp(): void
    {
        foreach (['email', 'sms', 'whatsapp'] as $channel) {
            $this->activeTemplate('refund_issued', $channel);
        }

        $attendee = Attendee::factory()->create(['email' => 'payer@example.com', 'mobile' => '8801711111111']);
        $payment = Payment::factory()->for($attendee)->create();
        $refund = Refund::factory()->for($payment)->create();

        RefundIssued::dispatch($refund);

        $channels = Notification::where('template_key', 'refund_issued')->pluck('channel')->all();
        $this->assertEqualsCanonicalizing(['email', 'sms'], $channels);
    }

    public function test_ticket_issued_queues_notification(): void
    {
        $this->activeTemplate('ticket_delivered', 'email');

        $attendee = Attendee::factory()->create(['email' => 'holder@example.com']);
        $ticket = Ticket::factory()->for($attendee)->create();

        TicketIssued::dispatch($ticket);

        $this->assertSame(1, Notification::where('template_key', 'ticket_delivered')->count());
    }
}
