<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Mail\NotificationMail;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The real email channel, since Phase 5. Transport is whatever
 * `MAIL_MAILER` resolves to (`config/mail.php`) — `log` in local/dev is a
 * safe zero-cost sandbox; pointing it at Postmark/SES/Resend (keys
 * already scaffolded in `config/services.php`) needs no code change,
 * mirroring how `SslCommerzClient` only needed credentials.
 */
class MailDriver implements NotificationChannelInterface
{
    public function send(Notification $notification): ChannelSendResult
    {
        try {
            $sentMessage = Mail::to($notification->recipient)->send(
                new NotificationMail($notification->subject ?? '', (string) $notification->body_rendered)
            );
        } catch (Throwable $e) {
            return new ChannelSendResult(
                status: ChannelSendResult::STATUS_FAILED,
                providerMessageId: null,
                segmentCount: null,
                costPaisa: null,
                errorMessage: $e->getMessage(),
            );
        }

        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_SENT,
            providerMessageId: $sentMessage?->getMessageId(),
            segmentCount: null,
            costPaisa: 0,
            provider: (string) config('mail.default'),
        );
    }
}
