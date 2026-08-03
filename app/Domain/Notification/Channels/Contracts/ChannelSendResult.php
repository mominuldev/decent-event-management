<?php

namespace App\Domain\Notification\Channels\Contracts;

/**
 * Result of {@see NotificationChannelInterface::send()}. Field names
 * mirror the columns on `notifications` they get written back into
 * (`provider_message_id`, `segment_count`, `cost_paisa`, `last_error`).
 */
final readonly class ChannelSendResult
{
    public const string STATUS_SENT = 'sent';

    public const string STATUS_FAILED = 'failed';

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $status,
        public ?string $providerMessageId,
        public ?int $segmentCount,
        public ?int $costPaisa,
        public ?string $errorMessage = null,
        public array $rawResponse = [],
        public ?string $provider = null,
    ) {}

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }
}
