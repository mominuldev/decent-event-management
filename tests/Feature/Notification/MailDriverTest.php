<?php

namespace Tests\Feature\Notification;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\MailDriver;
use App\Domain\Notification\Mail\NotificationMail;
use App\Domain\Notification\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

class MailDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_dispatches_mail_to_the_recipient_with_rendered_content(): void
    {
        Mail::fake();

        $notification = Notification::factory()->create([
            'channel' => 'email',
            'recipient' => 'attendee@example.com',
            'subject' => 'Your ticket is ready',
            'body_rendered' => '<p>Hello there</p>',
        ]);

        $result = (new MailDriver)->send($notification);

        $this->assertTrue($result->isSent());
        $this->assertSame(ChannelSendResult::STATUS_SENT, $result->status);

        Mail::assertSent(NotificationMail::class, function (NotificationMail $mail) use ($notification) {
            return $mail->hasTo($notification->recipient)
                && $mail->envelope()->subject === 'Your ticket is ready';
        });
    }

    public function test_send_reports_failure_when_the_mailer_throws(): void
    {
        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp unreachable'));

        $notification = Notification::factory()->create([
            'channel' => 'email',
            'recipient' => 'attendee@example.com',
            'body_rendered' => '<p>Hello</p>',
        ]);

        $result = (new MailDriver)->send($notification);

        $this->assertFalse($result->isSent());
        $this->assertSame('smtp unreachable', $result->errorMessage);
    }
}
