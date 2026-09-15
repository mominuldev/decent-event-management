<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Registration\Exceptions\RegistrationRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * "Pay now" was pressed on a registration that has nothing to pay right
 * now — already settled, under review, or sold out between attempts.
 *
 * Caller state, not a server fault, so it renders as a 422 with a stable
 * `code` the status page can branch on, the way
 * {@see RegistrationRejectedException}
 * does for the ticket form. `already_paid` in particular is a happy path
 * wearing an error status: the payer abandoned a checkout that had in fact
 * gone through, and the page's right move is to reload, not to apologise.
 */
class PaymentNotPayableException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'no_payable_payment',
    ) {
        parent::__construct($message);
    }

    public static function nothingToPay(): self
    {
        return new self('This registration has no payment awaiting initiation.');
    }

    public static function alreadyPaid(): self
    {
        return new self('This registration has already been paid.', 'already_paid');
    }

    public static function inProgress(): self
    {
        return new self('A payment for this registration is still being confirmed.', 'payment_in_progress');
    }

    public static function underReview(): self
    {
        return new self('A payment for this registration is under review.', 'payment_under_review');
    }

    public static function soldOut(): self
    {
        return new self('Tickets sold out before this payment could be retried.', 'sold_out');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
            'request_id' => $request->header('X-Request-Id'),
        ], 422);
    }
}
