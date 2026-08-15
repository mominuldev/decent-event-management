<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Maps a payment method / gateway name to its adapter — the one place
 * that branches on gateway name; domain code never does. `sslcommerz`
 * resolves to the real {@see SslCommerzClient} (Phase 4A); `bkash`,
 * `nagad`, and `rocket` stay on {@see FakeGateway} until their merchant
 * applications land (Phase 4B — see CLAUDE.md's External Dependencies).
 */
class PaymentGatewayResolver
{
    /**
     * Public so request validation can allowlist exactly what the resolver
     * can build — the two must not drift into a state where a payment row
     * is created with a method nothing can resolve.
     *
     * @var list<string>
     */
    public const array SUPPORTED_GATEWAYS = ['bkash', 'nagad', 'rocket', 'sslcommerz'];

    public function __construct(private readonly Application $app) {}

    public function forMethod(string $method): PaymentGatewayInterface
    {
        if (! in_array($method, self::SUPPORTED_GATEWAYS, true)) {
            throw new InvalidArgumentException("Unsupported payment gateway [{$method}].");
        }

        return match ($method) {
            'sslcommerz' => $this->app->make(SslCommerzClient::class),
            default => $this->app->make(FakeGateway::class),
        };
    }
}
