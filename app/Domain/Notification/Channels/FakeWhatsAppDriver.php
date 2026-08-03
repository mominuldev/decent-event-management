<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Str;

/**
 * Deterministic stand-in for Meta's WhatsApp Cloud API. Kept as
 * `whatsapp`'s driver until Meta approves the utility-category templates
 * (an unchecked external dependency in CLAUDE.md) — see
 * {@see NotificationChannelResolver}.
 * Cost is a flat per-conversation rate, matching how Meta actually bills
 * template messages (per conversation, not per character).
 *
 * Simulates a delivery failure when the recipient matches
 * {@see self::FAILURE_TRIGGER_RECIPIENT}.
 */
class FakeWhatsAppDriver implements NotificationChannelInterface
{
    public const string FAILURE_TRIGGER_RECIPIENT = '8801700000000';

    private const int COST_PAISA_PER_CONVERSATION = 100;

    public function send(Notification $notification): ChannelSendResult
    {
        if ($notification->recipient === self::FAILURE_TRIGGER_RECIPIENT) {
            return new ChannelSendResult(
                status: ChannelSendResult::STATUS_FAILED,
                providerMessageId: null,
                segmentCount: null,
                costPaisa: null,
                errorMessage: 'simulated_delivery_failure',
            );
        }

        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_SENT,
            providerMessageId: 'FAKE-WA-'.strtoupper(Str::random(16)),
            segmentCount: null,
            costPaisa: self::COST_PAISA_PER_CONVERSATION,
            provider: 'fake_whatsapp',
        );
    }
}
