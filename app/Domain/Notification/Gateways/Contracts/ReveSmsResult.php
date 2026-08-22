<?php

namespace App\Domain\Notification\Gateways\Contracts;

use App\Domain\Notification\Channels\SmsDriver;

/**
 * One recipient's outcome from a REVE send call.
 *
 * `messageId` is what `notifications.provider_message_id` stores and what
 * both the DLR poll (`/getmultistatus`) and the DLR push
 * (`/submitstatus`) key off, so a send that reports success without one
 * is treated as a failure by {@see SmsDriver}:
 * a message nothing can ever ask about again is not a delivered message.
 */
final readonly class ReveSmsResult
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public bool $accepted,
        public ?string $messageId,
        public ?string $recipient,
        public ?string $statusCode,
        public ?string $statusText,
        public array $raw = [],
    ) {}
}
