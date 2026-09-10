<?php

namespace App\Domain\Registration\Support;

use App\Domain\Payment\Actions\ExpirePaymentIntents;
use App\Domain\Payment\Actions\ReconcilePayments;
use App\Domain\Payment\Gateways\PaymentGatewayResolver;
use App\Domain\Registration\Actions\CreateRegistration;
use App\Domain\Shared\Models\User;

/**
 * How a registration came to exist, and therefore how its payment row is
 * shaped.
 *
 * {@see CreateRegistration} used to hardcode all of this for the public
 * checkout. It is a value object rather than an options array so the four
 * fields stay typed at PHPStan level 8, and so the two shapes that exist
 * are named once here instead of being spelled out at each call site —
 * getting one field wrong is not a cosmetic mistake (see `paymentChannel`).
 */
final readonly class RegistrationContext
{
    /**
     * @param  string  $source  `registrations.source` — where the row came from.
     * @param  string  $paymentMethod  `payments.method`. A gateway name for an online
     *                                 checkout, `cash` for money taken at a desk.
     * @param  string  $paymentChannel  `payments.channel`. See the note on counter().
     * @param  int|null  $createdByUserId  The staff member who typed it in, if any.
     * @param  bool  $paymentExpires  Whether the payment gets a reservation TTL.
     */
    private function __construct(
        public string $source,
        public string $paymentMethod,
        public string $paymentChannel,
        public ?int $createdByUserId,
        public bool $paymentExpires,
    ) {}

    /**
     * The public, unauthenticated checkout — the only shape that existed
     * before counter sales. Every value here is what CreateRegistration
     * previously hardcoded, so this path is unchanged.
     */
    public static function publicWeb(?string $paymentMethod = null): self
    {
        return new self(
            source: 'web_public',
            paymentMethod: $paymentMethod ?? self::defaultGateway(),
            paymentChannel: 'online',
            createdByUserId: null,
            // Reservation TTL starts at creation, not at gateway-session
            // open — an attendee who abandons before ever clicking "pay"
            // must still release capacity (D5).
            paymentExpires: true,
        );
    }

    /**
     * Staff registering someone at a desk, to be paid in cash.
     *
     * `paymentChannel` is **`manual`, and that is load-bearing rather than
     * a label.** {@see ExpirePaymentIntents} and {@see ReconcilePayments}
     * (and `payments:stuck`) all select on `channel != 'manual'` and then
     * hand the row's `method` to {@see PaymentGatewayResolver::forMethod()},
     * which throws for anything outside SUPPORTED_GATEWAYS. A cash payment
     * on any other channel would therefore either be swept into `expired`
     * — killing a registration somebody has already paid for — or crash
     * the nightly reconciliation. `method = 'cash'` is what distinguishes
     * it from a bank/wallet transfer, which is also `manual`.
     *
     * No expiry, for the same reason: capacity taken at a desk is not
     * abandoned checkout, and a sweeper must never release it.
     */
    public static function counter(User $actor): self
    {
        return new self(
            source: 'admin_counter',
            paymentMethod: 'cash',
            paymentChannel: 'manual',
            createdByUserId: (int) $actor->id,
            paymentExpires: false,
        );
    }

    public function isCounter(): bool
    {
        return $this->source === 'admin_counter';
    }

    /**
     * The gateway a checkout opens against when the caller doesn't name
     * one. Config rather than a literal so pointing the public flow at a
     * different gateway is a deploy-time change, not a code change.
     */
    private static function defaultGateway(): string
    {
        $method = config('services.payment.default_method');

        return is_string($method) && $method !== '' ? $method : 'paystation';
    }
}
