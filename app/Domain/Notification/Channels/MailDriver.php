<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Mail\MailPresentation;
use App\Domain\Notification\Mail\NotificationMail;
use App\Domain\Notification\Mail\ProvidesMailPresentation;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Facades\App;
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
                new NotificationMail(
                    $notification->subject ?? '',
                    (string) $notification->body_rendered,
                    $this->presentationFor($notification),
                    $notification->locale,
                )
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

    /**
     * Ask the notifiable what else belongs in its email — a ticket
     * contributes its QR code and gate details.
     *
     * Built under the notification's own locale. The Mailable wraps its
     * view render in `withLocale`, but the presentation is assembled
     * before that — without this, a Bangla email would arrive with English
     * gate details in the card beside its Bangla body.
     *
     * Called inside the send try/catch on purpose, so a failure to
     * resolve the QR fails the send rather than quietly producing a
     * ticket email with no code in it: the row goes back on the ADR-07
     * retry schedule and the reason shows up in the admin delivery log.
     * An email that looks complete and admits nobody at the gate is the
     * worse outcome.
     */
    private function presentationFor(Notification $notification): ?MailPresentation
    {
        $notifiable = $notification->notifiable;

        if (! $notifiable instanceof ProvidesMailPresentation) {
            return null;
        }

        $previous = App::getLocale();
        App::setLocale($notification->locale);

        try {
            return $notifiable->mailPresentation();
        } finally {
            App::setLocale($previous);
        }
    }
}
