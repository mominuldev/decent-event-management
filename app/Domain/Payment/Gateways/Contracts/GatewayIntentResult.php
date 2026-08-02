<?php

namespace App\Domain\Payment\Gateways\Contracts;

/**
 * Result of {@see PaymentGatewayInterface::createIntent()} — the redirect
 * target and gateway-side reference for a newly created payment session.
 */
final readonly class GatewayIntentResult
{
    /**
     * @param  array<string, mixed>  $rawResponse
     */
    public function __construct(
        public string $gatewayReference,
        public string $redirectUrl,
        public array $rawResponse = [],
    ) {}
}
