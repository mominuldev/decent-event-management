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

    private function activeTemplate(string $key, string $channel = 'email'): NotificationTemplate
    {
        return NotificationTemplate::factory()->create([
            'key' => $key,
            'channel' => $channel,
            'locale' => 'en',
            'version' => 1,
            'subject' => 'Subject {{full_name}}',
            'body' => 'Body {{full_name}}',
            'is_active' => true,
        ]);
    }

    public function test_registration_created_queues_a_notification_per_active_channel(): void
    {
        foreach (['email', 'sms', 'whatsapp'] as $channel) {
            $this->activeTemplate('registration_received', $channel);
        }

        $attendee = Attendee::factory()->create(['full_name' => 'Jane Doe', 'email' => 'jane@example.com', 'mobile' => '8801711111111']);
        $registration = Registration::factory()->for($attendee)->create();

        RegistrationCreated::dispatch($registration);

        $this->assertSame(3, Notification::where('template_key', 'registration_received')->count());

        $emailNotification = Notification::where('template_key', 'registration_received')->where('channel', 'email')->firstOrFail();
        $this->assertSame('Subject Jane Doe', $emailNotification->subject);
        $this->assertSame('Body Jane Doe', $emailNotification->body_rendered);
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
