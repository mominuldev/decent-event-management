<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\ChannelSendResult;
use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Models\Notification;
use Illuminate\Support\Str;

/**
 * Deterministic double for {@see MailDriver},
 * the real email channel since Phase 5. Bound explicitly in tests that
 * need failure-path coverage without mocking `Mail`. Simulates a bounce
 * when the recipient matches {@see self::FAILURE_TRIGGER_RECIPIENT}.
 */
class FakeEmailDriver implements NotificationChannelInterface
{
    public const string FAILURE_TRIGGER_RECIPIENT = 'bounce@fake-mail.test';

    public function send(Notification $notification): ChannelSendResult
    {
        if ($notification->recipient === self::FAILURE_TRIGGER_RECIPIENT) {
            return new ChannelSendResult(
                status: ChannelSendResult::STATUS_FAILED,
                providerMessageId: null,
                segmentCount: null,
                costPaisa: null,
                errorMessage: 'simulated_bounce',
            );
        }

        return new ChannelSendResult(
            status: ChannelSendResult::STATUS_SENT,
            providerMessageId: 'FAKE-EMAIL-'.strtoupper(Str::random(16)),
            segmentCount: null,
            costPaisa: 0,
            provider: 'fake_email',
        );
    }
}
