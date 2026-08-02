<?php

namespace App\Domain\Notification\Channels\Contracts;

use App\Domain\Notification\Models\Notification;

/**
 * One contract for every provider-agnostic channel driver (Email, SMS,
 * WhatsApp — docs/01 §1.6, ADR-07). Drivers only ever see a queued
 * `Notification` row; they never get called directly from
 * request-handling code.
 */
interface NotificationChannelInterface
{
    public function send(Notification $notification): ChannelSendResult;
}
