<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Maps a `notifications.channel` value to its driver. Every case resolves
 * to a `Fake*Driver` until Phase 5 swaps in the real provider — this is
 * the one place that changes, dispatch code never branches on channel name.
 */
class NotificationChannelResolver
{
    public function __construct(private readonly Application $app) {}

    public function forChannel(string $channel): NotificationChannelInterface
    {
        return match ($channel) {
            'email' => $this->app->make(FakeEmailDriver::class),
            'sms' => $this->app->make(FakeSmsDriver::class),
            'whatsapp' => $this->app->make(FakeWhatsAppDriver::class),
            default => throw new InvalidArgumentException("Unsupported notification channel [{$channel}]."),
        };
    }
}
