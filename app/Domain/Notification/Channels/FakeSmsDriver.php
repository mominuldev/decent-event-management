<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Support\SmsSegmentCalculator;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for the real Bangladesh SMS gateway. Kept as
 * `sms`'s driver until a vendor is chosen — see
 * {@see NotificationChannelResolver}.
 * Segment/cost accounting uses the real GSM-7/Unicode budgeting rule
 * ({@see SmsSegmentCalculator}) so cost-tracking code can be built and
 * tested against it now.
 *
 * Simulates a delivery failure when the recipient matches
 * {@see self::FAILURE_TRIGGER_RECIPIENT}.
 */
class FakeSmsDriver implements NotificationChannelInterface
{
    public const string FAILURE_TRIGGER_RECIPIENT = '8801700000000';

    private const int COST_PAISA_PER_SEGMENT = 50;

    public function send(Notification $notification): ChannelSendResult
    {
        if ($notification->recipient === self::FAILURE_TRIGGER_RECIPIENT) {
            return new ChannelSendResult(
                status: ChannelSendResult::STATUS_FAILED,
                providerMessageId: null,
                segmentCount: null,
                costPaisa: null,
                errorMessage: 'simulated_gateway_failure',
            );
        }

        $segments = SmsSegmentCalculator::segmentCount((string) $notification->body_rendered);

        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_SENT,
            providerMessageId: 'FAKE-SMS-'.strtoupper(Str::random(16)),
            segmentCount: $segments,
            costPaisa: $segments * self::COST_PAISA_PER_SEGMENT,
            provider: 'fake_sms',
        );
    }
}
