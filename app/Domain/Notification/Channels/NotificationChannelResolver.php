<?php

namespace App\Domain\Notification\Channels;

use App\Domain\Notification\Channels\Contracts\NotificationChannelInterface;
use App\Domain\Notification\Gateways\ReveSmsClient;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Maps a `notifications.channel` value to its driver — the one place
 * that branches on channel name, dispatch code never does. `email` is
 * real ({@see MailDriver}) since Phase 5; `sms` is real
 * ({@see SmsDriver}, REVE Systems) once credentials are configured.
 *
 * `sms` falls back to {@see FakeSmsDriver} when they are not, rather than
 * throwing: a developer checkout and CI have no REVE account, and a
 * notification pipeline that dies on the first SMS would take the whole
 * outbox down with it — including the email half that works. The
 * fallback is visible on every row it writes (`notifications.provider` is
 * `fake_sms`, not `revesms`), so it cannot be mistaken for real delivery
 * in the admin delivery log.
 *
 * `whatsapp` stays on its `Fake*Driver` — Meta has not approved the
 * templates, an unchecked external dependency in CLAUDE.md, not missing
 * engineering.
 */
class NotificationChannelResolver
{
    public function __construct(private readonly Application $app) {}

    public function forChannel(string $channel): NotificationChannelInterface
    {
        return match ($channel) {
            'email' => $this->app->make(MailDriver::class),
            'sms' => ReveSmsClient::isConfigured()
                ? $this->app->make(SmsDriver::class)
                : $this->app->make(FakeSmsDriver::class),
            'whatsapp' => $this->app->make(FakeWhatsAppDriver::class),
            default => throw new InvalidArgumentException("Unsupported notification channel [{$channel}]."),
        };
    }
}
