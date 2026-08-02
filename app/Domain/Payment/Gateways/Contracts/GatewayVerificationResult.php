<?php

namespace App\Domain\Payment\Gateways\Contracts;

/**
 * Authoritative result of {@see PaymentGatewayInterface::verify()} — the
 * only signal allowed to move a payment to `succeeded` (docs/06 §6.6).
 */
final readonly class GatewayVerificationResult
{
    public const string STATUS_SUCCEEDED = 'succeeded';

    public const string STATUS_FAILED = 'failed';

    public const string STATUS_PENDING = 'pending';

    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $status,
        public ?int $settledAmountPaisa,
        public ?string $gatewayTransactionId,
        public array $rawResponse = [],
    ) {}

    public function isSucceeded(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }
}
