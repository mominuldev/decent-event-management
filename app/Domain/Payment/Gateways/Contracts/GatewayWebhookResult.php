<?php

namespace App\Domain\Payment\Gateways\Contracts;

/**
 * Parsed result of {@see PaymentGatewayInterface::parseWebhook()}. Carries
 * `signatureValid` explicitly so callers never mistake "parsed" for
 * "trusted" — an invalid signature must never drive a state transition.
 */
final readonly class GatewayWebhookResult
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $gatewayReference,
        public ?string $gatewayTransactionId,
        public string $status,
        public bool $signatureValid,
        public array $rawPayload = [],
    ) {}
}
