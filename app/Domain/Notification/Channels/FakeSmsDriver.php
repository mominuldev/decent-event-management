<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for the real Bangladesh SMS gateway (Phase 5).
 * Segment/cost accounting mirrors the real budgeting model (docs §1.6)
 * so cost-tracking code can be built and tested against it now: a fixed
 * per-segment rate, one segment per 160 characters.
 *
 * Simulates a delivery failure when the recipient matches
 * {@see self::FAILURE_TRIGGER_RECIPIENT}.
 */
class FakeSmsDriver implements NotificationChannelInterface
{
    public const string FAILURE_TRIGGER_RECIPIENT = '8801700000000';

    private const int CHARACTERS_PER_SEGMENT = 160;

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

        $segments = max(1, (int) ceil(mb_strlen((string) $notification->body_rendered) / self::CHARACTERS_PER_SEGMENT));

        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_SENT,
            providerMessageId: 'FAKE-SMS-'.strtoupper(Str::random(16)),
            segmentCount: $segments,
            costPaisa: $segments * self::COST_PAISA_PER_SEGMENT,
        );
    }
}
