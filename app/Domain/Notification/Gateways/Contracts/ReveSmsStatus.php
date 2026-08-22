<?php

namespace App\Domain\Notification\Gateways\Contracts;

use App\Domain\Notification\Gateways\ReveSmsDeliveryState;

/**
 * One message's delivery state, from `/getstatus` or `/getmultistatus`.
 *
 * `state` is this codebase's vocabulary, not REVE's — see
 * {@see ReveSmsDeliveryState}, which is
 * also what the inbound DLR push maps onto, so a status learned by
 * polling and the same status learned by callback produce identical rows.
 */
final readonly class ReveSmsStatus
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $messageId,
        public string $state,
        public ?string $providerStatus,
        public array $raw = [],
    ) {}
}
