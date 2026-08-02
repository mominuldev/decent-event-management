<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Maps a payment method / gateway name to its adapter. Every case
 * resolves to {@see FakeGateway} until Phase 4 replaces each with a real
 * client (`BkashClient`, `NagadClient`, `RocketClient`, `SslCommerzClient`)
 * — this is the one place that changes, domain code never branches on
 * gateway name itself.
 */
class PaymentGatewayResolver
{
    private const array SUPPORTED_GATEWAYS = ['bkash', 'nagad', 'rocket', 'sslcommerz'];

    public function __construct(private readonly Application $app) {}

    public function forMethod(string $method): PaymentGatewayInterface
    {
        if (! in_array($method, self::SUPPORTED_GATEWAYS, true)) {
            throw new InvalidArgumentException("Unsupported payment gateway [{$method}].");
        }

        return $this->app->make(FakeGateway::class);
    }
}
