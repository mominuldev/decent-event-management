<?php

namespace App\Domain\Payment\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The payment's gateway cannot be told to refund, so the refund has to
 * have been performed by a human elsewhere before this system records it.
 *
 * PayStation is the case that brought this into existence: it reports
 * `refund` as a transaction status but publishes no endpoint to cause
 * one, so the money moves in their merchant panel and nowhere else.
 * Recording a refund here without evidence of that would put a
 * `refunded` payment, a voided ticket and a released seat in front of an
 * attendee whose money is still with the gateway.
 *
 * Deliberately extends RuntimeException rather than InvalidArgumentException:
 * the admin refund controller catches the latter and flattens it to a
 * generic `refund_failed`, which would lose the distinct `code` the SPA
 * needs in order to show the acknowledgement fields.
 */
class OutOfBandRefundRequiredException extends RuntimeException
{
    public const string CODE = 'out_of_band_refund_acknowledgement_required';

    public static function forMethod(string $method): self
    {
        return new self(
            "The {$method} gateway has no refund API, so this refund must be issued in the gateway's own "
            .'merchant panel first. Re-submit with `acknowledged_out_of_band` set and the panel\'s refund '
            .'reference in `gateway_refund_reference`.'
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'code' => self::CODE,
            'message' => $this->getMessage(),
            'errors' => [
                'acknowledged_out_of_band' => ['Confirm the refund has already been issued at the gateway.'],
                'gateway_refund_reference' => ['Enter the reference the gateway gave for that refund.'],
            ],
            'request_id' => $request->header('X-Request-Id'),
        ], 422);
    }
}
