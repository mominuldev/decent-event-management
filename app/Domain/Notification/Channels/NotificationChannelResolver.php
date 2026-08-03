<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Maps a `notifications.channel` value to its driver — the one place
 * that branches on channel name, dispatch code never does. `email` is
 * real ({@see MailDriver}) since Phase 5. `sms`/`whatsapp` stay on their
 * `Fake*Driver` because no Bangladesh SMS vendor is chosen and Meta
 * hasn't approved WhatsApp templates yet — both unchecked external
 * dependencies in CLAUDE.md, not missing engineering.
 */
class NotificationChannelResolver
{
    public function __construct(private readonly Application $app) {}

    public function forChannel(string $channel): NotificationChannelInterface
    {
        return match ($channel) {
            'email' => $this->app->make(MailDriver::class),
            'sms' => $this->app->make(FakeSmsDriver::class),
            'whatsapp' => $this->app->make(FakeWhatsAppDriver::class),
            default => throw new InvalidArgumentException("Unsupported notification channel [{$channel}]."),
        };
    }
}
