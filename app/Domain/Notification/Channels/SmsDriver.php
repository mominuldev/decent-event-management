<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Gateways\ReveSmsClient;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Support\Msisdn;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use App\Jobs\SendNotificationJob;
use Throwable;

/**
 * The real SMS channel, over REVE Systems (smpp.revesms.com). Resolved
 * for `sms` in place of {@see FakeSmsDriver} once credentials are
 * configured — see {@see NotificationChannelResolver}.
 *
 * It is a thin translation between the outbox row and
 * {@see ReveSmsClient}: format the recipient, send, and report back in
 * the shape `notifications` stores. Retry, backoff and the kill switch
 * all live in {@see SendNotificationJob} above it, so this
 * never decides whether to try again.
 *
 * Cost is computed locally from the segment count rather than read off
 * the response — REVE bills per segment against a prepaid balance and
 * returns no price on a send, so `services.revesms.cost_paisa_per_segment`
 * has to match the contracted rate for the delivery-cost report to mean
 * anything. It is reporting, not billing.
 */
class SmsDriver implements NotificationChannelInterface
{
    public function __construct(private readonly ReveSmsClient $client) {}

    public function send(Notification $notification): ChannelSendResult
    {
        $body = (string) $notification->body_rendered;
        $segments = SmsSegmentCalculator::segmentCount($body);
        $recipient = Msisdn::format($notification->recipient);

        if ($recipient === null) {
            // Not retryable — the number will not become dialable on the
            // next attempt — but the job's attempt counter is what ends
            // the retries, so this reports the reason and lets that run.
            return $this->failed("unroutable_recipient: {$notification->recipient}");
        }

        try {
            $results = $this->client->sendText(
                ReveSmsClient::defaultSenderId(),
                [$recipient],
                $body,
            );
        } catch (Throwable $e) {
            return $this->failed('gateway_error: '.$e->getMessage());
        }

        $result = $results[0];

        if (! $result->accepted || $result->messageId === null) {
            // A send reported as accepted but carrying no message id is
            // treated as a failure on purpose: without one, no DLR — push
            // or poll — can ever be matched back to this row, so the
            // notification would sit at `sent` forever with no way to learn
            // it never arrived.
            return $this->failed(
                $result->accepted
                    ? 'gateway_accepted_without_message_id: nothing can match a delivery receipt to this row'
                    : $this->errorFrom($result->statusCode, $result->statusText),
                $result->raw,
            );
        }

        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_SENT,
            providerMessageId: $result->messageId,
            segmentCount: $segments,
            costPaisa: $segments * $this->costPerSegment(),
            rawResponse: $result->raw,
            provider: 'revesms',
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function failed(string $error, array $raw = []): ChannelSendResult
    {
        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_FAILED,
            providerMessageId: null,
            segmentCount: null,
            costPaisa: null,
            errorMessage: mb_substr($error, 0, 500),
            rawResponse: $raw,
            provider: 'revesms',
        );
    }

    private function errorFrom(?string $code, ?string $text): string
    {
        $reason = trim(($code ?? '').' '.($text ?? ''));

        return $reason === '' ? 'gateway_rejected' : 'gateway_rejected: '.$reason;
    }

    private function costPerSegment(): int
    {
        return max(0, (int) config('services.revesms.cost_paisa_per_segment', 0));
    }
}
