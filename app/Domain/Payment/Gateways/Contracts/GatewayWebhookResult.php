<?php

namespace App\Domain\Payment\Gateways\Contracts;

/**
 * Parsed result of {@see PaymentGatewayInterface::parseWebhook()}. Carries
 * the authenticity of the request explicitly so callers never mistake
 * "parsed" for "trusted".
 *
 * Three states, not two, because "we checked the signature and it failed"
 * and "this gateway signs nothing at all" are different facts and must
 * lead to different behaviour:
 *
 *  - `verified` — a real signature was checked and matched.
 *  - `invalid`  — a signature was present or expected and did not match.
 *                 Never acted on: something is forging or misconfigured.
 *  - `unsigned` — the gateway publishes no signature scheme. PayStation's
 *                 IPN is a plain unauthenticated JSON POST (their docs:
 *                 "Auth: None"), so there is nothing to verify and
 *                 reporting `signatureValid = false` would wrongly imply a
 *                 failed check. Acting on it is safe only because a
 *                 webhook never settles a payment by itself — it merely
 *                 prompts a fresh server-to-server {@see VerifyPayment}
 *                 call, which re-reads status and amount from the gateway.
 *
 * `signatureValid` stays as the recorded audit fact; use
 * {@see recordedSignatureValid()} when writing it to the database, so an
 * unsigned webhook stores NULL rather than a false that reads as a
 * failed check.
 */
final readonly class GatewayWebhookResult
{
    public const string AUTH_VERIFIED = 'verified';

    public const string AUTH_INVALID = 'invalid';

    public const string AUTH_UNSIGNED = 'unsigned';

    public string $authenticity;

    /**
     * @param  array<string, mixed>  $rawPayload
     * @param  string|null  $authenticity  Derived from `$signatureValid` when omitted, so
     *                                     every existing signed-gateway call site is unchanged.
     */
    public function __construct(
        public string $gatewayReference,
        public ?string $gatewayTransactionId,
        public string $status,
        public bool $signatureValid,
        public array $rawPayload = [],
        ?string $authenticity = null,
    ) {
        $this->authenticity = $authenticity ?? ($signatureValid ? self::AUTH_VERIFIED : self::AUTH_INVALID);
    }

    /**
     * Whether this request may prompt a server-to-server verification.
     * Only a positively invalid signature is refused — see the class
     * docblock for why `unsigned` is allowed through.
     */
    public function mayTriggerVerification(): bool
    {
        return $this->authenticity !== self::AUTH_INVALID;
    }

    /**
     * What belongs in `payment_transactions.signature_valid` — NULL where
     * the gateway signs nothing, since the column records the outcome of a
     * check that in that case never happened.
     */
    public function recordedSignatureValid(): ?bool
    {
        return $this->authenticity === self::AUTH_UNSIGNED ? null : $this->signatureValid;
    }
}
