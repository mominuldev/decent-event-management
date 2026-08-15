<?php

namespace App\Domain\Registration\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A registration the caller may not make — sold out, or a ticket that is
 * not sold to the participant type they chose.
 *
 * This is caller error, not a server fault, so it must not surface as a
 * 500: the public ticket form shows the API's `message` verbatim, and
 * "Something went wrong" in place of "this ticket is sold out" leaves the
 * reader with nothing to act on. Laravel calls `render()` if an exception
 * defines one, so the uniform error envelope is produced here rather than
 * needing a handler registration in bootstrap/app.php.
 */
class RegistrationRejectedException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly string $errorCode = 'registration_rejected',
    ) {
        parent::__construct($message);
    }

    public static function soldOut(): self
    {
        return new self('Tickets are sold out or capacity is full.', 'sold_out');
    }

    public static function participantTypeNotAllowed(string $participantType): self
    {
        return new self(
            "This ticket is not available to the selected participant type [{$participantType}].",
            'participant_type_not_allowed',
        );
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
