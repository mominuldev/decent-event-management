<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Channels\FakeSmsDriver;
use App\Domain\Notification\Channels\NotificationChannelResolver;
use App\Domain\Notification\Channels\SmsDriver;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\NotificationEvent;
use App\Domain\Shared\Models\EventSetting;
use App\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The SMS channel end to end: which driver gets resolved, what the real
 * one writes back onto the outbox row, and how a delivery receipt settles
 * it.
 */
class SmsDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->configureGateway();
    }

    private function configureGateway(): void
    {
        config([
            'services.revesms.base_url' => 'https://smpp.revesms.com:7790',
            'services.revesms.api_key' => 'test-api-key',
            'services.revesms.secret_key' => 'test-secret',
            'services.revesms.sender_id' => 'DEC100',
            'services.revesms.auth_style' => 'body',
            'services.revesms.method' => 'post',
            'services.revesms.cost_paisa_per_segment' => 50,
        ]);
    }

    private function smsNotification(string $recipient = '01711223344', string $body = 'Hello'): Notification
    {
        return Notification::factory()->create([
            'channel' => 'sms',
            'recipient' => $recipient,
            'body_rendered' => $body,
            'status' => 'queued',
            'sent_at' => null,
            'provider' => null,
        ]);
    }

    public function test_the_real_driver_is_resolved_once_credentials_exist(): void
    {
        $this->assertInstanceOf(SmsDriver::class, app(NotificationChannelResolver::class)->forChannel('sms'));
    }

    public function test_it_falls_back_to_the_fake_driver_when_unconfigured(): void
    {
        // A dev checkout and CI have no REVE account. Throwing here would
        // take the whole outbox down — including the email half that works.
        config(['services.revesms.api_key' => null]);

        $this->assertInstanceOf(FakeSmsDriver::class, app(NotificationChannelResolver::class)->forChannel('sms'));
    }

    public function test_a_successful_send_records_the_provider_message_id_and_cost(): void
    {
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1373104'])]);

        $notification = $this->smsNotification();

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $notification->refresh();

        $this->assertSame('sent', $notification->status);
        $this->assertSame('revesms', $notification->provider);
        $this->assertSame('1373104', $notification->provider_message_id);
        $this->assertSame(1, $notification->segment_count);
        $this->assertSame(50, $notification->cost_paisa);
    }

    public function test_the_recipient_reaches_the_gateway_as_an_msisdn(): void
    {
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        (new SendNotificationJob($this->smsNotification('01711223344')->id))
            ->handle(app(NotificationChannelResolver::class));

        // The outbox stores whatever the attendee gave; the gateway needs a
        // country code and no trunk prefix.
        Http::assertSent(fn ($request): bool => $request->data()['toUser'] === '8801711223344');
    }

    public function test_bangla_is_billed_at_the_unicode_segment_rate(): void
    {
        Http::fake(['*' => Http::response(['Status' => '0', 'Message_ID' => '1'])]);

        // 80 Bangla characters: over the 70-per-segment Unicode budget, so
        // two segments — where the same length in Latin would be one.
        $body = str_repeat('অ', 80);
        $notification = $this->smsNotification('01711223344', $body);

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $notification->refresh();

        $this->assertSame(2, $notification->segment_count);
        $this->assertSame(100, $notification->cost_paisa);
    }

    public function test_an_accepted_send_with_no_message_id_is_treated_as_a_failure(): void
    {
        // Without an id, no receipt — push or poll — can ever be matched
        // back to this row, so it would sit at `sent` forever with no way
        // to learn it never arrived. Better to retry it.
        Http::fake(['*' => Http::response(['Status' => '0'])]);

        $notification = $this->smsNotification();

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $notification->refresh();

        $this->assertSame('queued', $notification->status);
        $this->assertSame(1, $notification->attempts);
        $this->assertStringContainsString('without_message_id', (string) $notification->last_error);
    }

    public function test_a_rejected_send_is_queued_for_retry_with_the_gateway_reason(): void
    {
        Http::fake(['*' => Http::response(['Status' => '105', 'Text' => 'Invalid callerID'])]);

        $notification = $this->smsNotification();

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $notification->refresh();

        $this->assertSame('queued', $notification->status);
        $this->assertStringContainsString('Invalid callerID', (string) $notification->last_error);
        $this->assertNull($notification->provider_message_id);
    }

    public function test_an_undialable_recipient_fails_without_calling_the_gateway(): void
    {
        Http::fake();

        $notification = $this->smsNotification('n/a');

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        Http::assertNothingSent();
        $this->assertStringContainsString('unroutable_recipient', (string) $notification->refresh()->last_error);
    }

    public function test_a_connection_failure_is_reported_rather_than_escaping_the_driver(): void
    {
        // A throw here would bypass the outbox's own attempt counting and
        // hand the queue a raw exception instead of a row that records why.
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $notification = $this->smsNotification();

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $notification->refresh();

        $this->assertSame('queued', $notification->status);
        $this->assertStringContainsString('gateway_error', (string) $notification->last_error);
    }

    public function test_the_kill_switch_still_cancels_before_the_gateway_is_called(): void
    {
        Http::fake();
        EventSetting::query()->updateOrCreate(
            ['key' => 'notification.sms_enabled'],
            ['value' => 'false', 'type' => 'boolean', 'group' => 'notification', 'label' => 'SMS enabled', 'is_public' => false],
        );

        $notification = $this->smsNotification();

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        Http::assertNothingSent();
        $this->assertSame('cancelled', $notification->refresh()->status);
    }

    // --- Delivery receipts ------------------------------------------------

    private function sentNotification(string $messageId = '1373104'): Notification
    {
        return Notification::factory()->create([
            'channel' => 'sms',
            'recipient' => '8801711223344',
            'status' => 'sent',
            'provider' => 'revesms',
            'provider_message_id' => $messageId,
            'sent_at' => now()->subMinutes(10),
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function dlr(array $overrides = []): TestResponse
    {
        return $this->postJson('/webhooks/sms/dlr', array_merge([
            'apikey' => 'test-api-key',
            'secretkey' => 'test-secret',
            'messageid' => '1373104',
            'text' => 'DELIVRD',
        ], $overrides));
    }

    public function test_a_delivered_receipt_settles_the_notification(): void
    {
        $notification = $this->sentNotification();

        $this->dlr()->assertOk();

        $notification->refresh();

        $this->assertSame('delivered', $notification->status);
        $this->assertNotNull($notification->delivered_at);
        $this->assertSame('delivered', $notification->events()->sole()->event);
    }

    public function test_a_failed_receipt_bounces_the_notification(): void
    {
        $notification = $this->sentNotification();

        $this->dlr(['text' => 'UNDELIV'])->assertOk();

        $notification->refresh();

        $this->assertSame('bounced', $notification->status);
        $this->assertStringContainsString('UNDELIV', (string) $notification->last_error);
    }

    public function test_a_pending_receipt_is_recorded_without_changing_the_status(): void
    {
        $notification = $this->sentNotification();

        $this->dlr(['text' => 'ACCEPTD'])->assertOk();

        $notification->refresh();

        // The carrier took it; the handset has not had it.
        $this->assertSame('sent', $notification->status);
        $this->assertSame('status', $notification->events()->sole()->event);
    }

    public function test_a_repeated_receipt_is_a_no_op_that_still_records_the_event(): void
    {
        $notification = $this->sentNotification();

        $this->dlr()->assertOk();
        $this->dlr()->assertOk();

        $notification->refresh();

        $this->assertSame('delivered', $notification->status);
        // Carriers really do re-send receipts, and a poll running alongside
        // a push sees the same status twice as a matter of course. Neither
        // may throw an illegal-transition exception out of a webhook.
        $this->assertSame(2, $notification->events()->count());
    }

    public function test_wrong_credentials_are_refused(): void
    {
        $notification = $this->sentNotification();

        $this->dlr(['secretkey' => 'wrong'])->assertStatus(401);

        $this->assertSame('sent', $notification->refresh()->status);
        $this->assertSame(0, NotificationEvent::query()->count());
    }

    public function test_the_callback_is_refused_outright_when_no_credentials_are_configured(): void
    {
        // An unauthenticated endpoint that rewrites delivery state is worse
        // than one that is switched off.
        config(['services.revesms.api_key' => null, 'services.revesms.dlr_api_key' => null]);

        $this->dlr()->assertStatus(401);
    }

    public function test_an_unknown_message_id_answers_exactly_as_a_known_one_does(): void
    {
        $known = $this->dlr(['messageid' => '999'])->assertOk();

        $this->sentNotification('1373104');
        $unknown = $this->dlr();

        // A 404 here would be an oracle for enumerating live message ids,
        // and REVE would read the error as a failed callback and retry
        // forever.
        $this->assertSame($unknown->getContent(), $known->getContent());
    }

    public function test_the_stored_payload_never_includes_the_api_credentials(): void
    {
        $notification = $this->sentNotification();

        $this->dlr()->assertOk();

        $payload = $notification->events()->sole()->raw_payload;

        // raw_payload is rendered in the admin delivery timeline and lands
        // in every database backup.
        $this->assertArrayNotHasKey('apikey', (array) $payload);
        $this->assertArrayNotHasKey('secretkey', (array) $payload);
        $this->assertSame('DELIVRD', ((array) $payload)['text']);
    }

    public function test_a_get_callback_is_accepted_too(): void
    {
        $notification = $this->sentNotification();

        // The vendor's collection shows both verbs; a gateway configured
        // for the other one would otherwise drop every receipt.
        $this->getJson('/webhooks/sms/dlr?apikey=test-api-key&secretkey=test-secret&messageid=1373104&text=DELIVRD')
            ->assertOk();

        $this->assertSame('delivered', $notification->refresh()->status);
    }

    public function test_polling_settles_what_no_callback_ever_reported(): void
    {
        $delivered = $this->sentNotification('7331');
        $failed = $this->sentNotification('7332');
        $pending = $this->sentNotification('7333');

        Http::fake(['*' => Http::response([
            ['messageid' => '7331', 'status' => 'DELIVRD'],
            ['messageid' => '7332', 'status' => 'REJECTD'],
            ['messageid' => '7333', 'status' => 'ACCEPTD'],
        ])]);

        $this->artisan('sms:poll-dlr')->assertExitCode(0);

        $this->assertSame('delivered', $delivered->refresh()->status);
        $this->assertSame('bounced', $failed->refresh()->status);
        $this->assertSame('sent', $pending->refresh()->status);
    }

    public function test_polling_skips_a_message_that_is_too_new_to_have_a_receipt(): void
    {
        Http::fake();

        Notification::factory()->create([
            'channel' => 'sms',
            'status' => 'sent',
            'provider' => 'revesms',
            'provider_message_id' => '7331',
            'sent_at' => now(),
        ]);

        $this->artisan('sms:poll-dlr')->assertExitCode(0);

        // Asking the instant a message is accepted burns one request per
        // row to be told nothing.
        Http::assertNothingSent();
    }

    public function test_polling_is_a_no_op_when_the_gateway_is_not_configured(): void
    {
        config(['services.revesms.api_key' => null]);
        Http::fake();

        $this->artisan('sms:poll-dlr')->assertExitCode(0);

        Http::assertNothingSent();
    }
}
