<?php

namespace App\Domain\Payment\Gateways;

use App\Domain\Payment\Actions\ReconcilePayments;
use App\Domain\Payment\Gateways\Contracts\PaymentGatewayInterface;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;

/**
 * Maps a payment method / gateway name to its adapter — the one place
 * that branches on gateway name; domain code never does.
 *
 * `paystation` resolves to the real {@see PayStationClient}. It is an
 * aggregator, so its hosted checkout already offers bKash, Nagad, Rocket,
 * Upay and cards; the standalone `bkash`/`nagad`/`rocket` methods are kept
 * for a possible direct integration later and stay on {@see FakeGateway}
 * until their own merchant applications land (Phase 4B — see CLAUDE.md's
 * External Dependencies).
 *
 * `sslcommerz` was removed on 2026-09-10 and is deliberately **not**
 * mapped to anything: a payment row left over from that era must fail to
 * resolve rather than quietly resolve to a fake that would happily report
 * a real transaction as settled. Both read paths that touch historical
 * rows ({@see ReconcilePayments} and
 * `payments:stuck`) already catch a resolver failure and log it.
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
    public const array SUPPORTED_GATEWAYS = ['bkash', 'nagad', 'rocket', 'paystation'];

    public function __construct(private readonly Application $app) {}

    public function forMethod(string $method): PaymentGatewayInterface
    {
        if (! in_array($method, self::SUPPORTED_GATEWAYS, true)) {
            throw new InvalidArgumentException("Unsupported payment gateway [{$method}].");
        }

        return match ($method) {
            'paystation' => $this->app->make(PayStationClient::class),
            default => $this->app->make(FakeGateway::class),
        };
    }
}
