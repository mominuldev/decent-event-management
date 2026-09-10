<?php

namespace App\Domain\Payment\Exceptions;

use App\Domain\Registration\Exceptions\RegistrationRejectedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Cash the system will not accept against this payment.
 *
 * Both cases are operator error at a desk, not a server fault, so they
 * render as a 422 in the uniform envelope with a message the operator can
 * act on — the amount they should have taken, or the state the payment is
 * actually in. A 500 in front of a queue tells them nothing and leaves
 * them unsure whether the money was recorded.
 *
 * `render()` lives here for the same reason it does on
 * {@see RegistrationRejectedException}:
 * Laravel calls it if an exception defines one, so no handler registration
 * is needed in bootstrap/app.php.
 */
class CashCollectionRejectedException extends RuntimeException
{
    /** @param array<string, list<string>> $fieldErrors */
    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly array $fieldErrors = [],
    ) {
        parent::__construct($message);
    }

    public static function amountMismatch(int $receivedPaisa, int $duePaisa): self
    {
        $received = self::bdt($receivedPaisa);
        $due = self::bdt($duePaisa);

        return new self(
            "Cash received (৳{$received}) does not match the amount due (৳{$due}). "
            .'A counter sale must be settled in full.',
            'cash_amount_mismatch',
            ['amount_received_paisa' => ["Enter ৳{$due}, the full amount due."]],
        );
    }

    public static function notCollectable(string $status): self
    {
        // `initiated` is the one worth naming specifically: it means a
        // gateway session is open, so taking cash now risks the attendee
        // being charged twice for the same seat.
        $why = $status === 'initiated'
            ? 'An online payment is already in progress for this registration — wait for it to settle or expire before taking cash.'
            : "A payment with status '{$status}' cannot be settled in cash.";

        return new self($why, 'payment_not_collectable');
    }

    public function render(Request $request): JsonResponse
    {
        $body = [
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ];

        if ($this->fieldErrors !== []) {
            $body['errors'] = $this->fieldErrors;
        }

        $body['request_id'] = $request->header('X-Request-Id');

        return response()->json($body, 422);
    }

    private static function bdt(int $paisa): string
    {
        return number_format($paisa / 100, 2);
    }
}
