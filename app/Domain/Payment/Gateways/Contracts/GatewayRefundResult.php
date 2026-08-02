<?php

namespace App\Domain\Payment\Gateways\Contracts;

/**
 * Result of {@see PaymentGatewayInterface::refund()}.
 */
final readonly class GatewayRefundResult
{
    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_PENDING = 'pending';

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $status,
        public ?string $gatewayReference,
        public array $rawResponse = [],
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
