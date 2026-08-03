<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Channels\FakeSmsDriver;
use App\Domain\Notification\Channels\NotificationChannelResolver;
use App\Domain\Notification\Mail\NotificationMail;
use App\Domain\Notification\Models\Notification;
use App\Domain\Shared\Models\EventSetting;
use App\Jobs\SendNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendNotificationJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_marks_notification_sent_on_success(): void
    {
        Mail::fake();

        $notification = Notification::factory()->create([
            'channel' => 'email',
            'status' => 'queued',
            'attempts' => 0,
            'recipient' => 'someone@example.com',
        ]);

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $notification->refresh();
        $this->assertSame('sent', $notification->status);
        $this->assertNotNull($notification->sent_at);

        Mail::assertSent(NotificationMail::class);
    }

    public function test_job_retries_then_fails_after_exhausting_attempts(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'sms',
            'status' => 'queued',
            'attempts' => 0,
            'max_attempts' => 5,
            'recipient' => FakeSmsDriver::FAILURE_TRIGGER_RECIPIENT,
        ]);

        $job = new SendNotificationJob($notification->id);

        for ($i = 1; $i <= 4; $i++) {
            $job->handle(app(NotificationChannelResolver::class));
            $notification->refresh();
            $this->assertSame('queued', $notification->status, "Expected still-queued after attempt {$i}");
            $this->assertSame($i, $notification->attempts);
        }

        $job->handle(app(NotificationChannelResolver::class));
        $notification->refresh();

        $this->assertSame('failed', $notification->status);
        $this->assertSame(5, $notification->attempts);
        $this->assertNotNull($notification->failed_at);
        $this->assertSame('simulated_gateway_failure', $notification->last_error);
    }

    public function test_job_cancels_notification_when_channel_kill_switch_is_disabled(): void
    {
        EventSetting::factory()->create(['key' => 'notification.sms_enabled', 'type' => 'bool', 'value' => '0']);

        $notification = Notification::factory()->create([
            'channel' => 'sms',
            'status' => 'queued',
            'recipient' => '8801711111111',
        ]);

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $this->assertSame('cancelled', $notification->refresh()->status);
    }

    public function test_job_is_a_noop_when_notification_is_already_terminal(): void
    {
        $notification = Notification::factory()->create([
            'channel' => 'email',
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        (new SendNotificationJob($notification->id))->handle(app(NotificationChannelResolver::class));

        $this->assertSame('sent', $notification->refresh()->status);
    }
}
