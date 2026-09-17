<?php

namespace App\Domain\Registration\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * A registration the caller may not make — sold out, not yet on sale, or
 * a ticket that is not sold to the participant type they chose.
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

    /**
     * The ticket type is listed but its sale window is not open — either
     * not yet, in which case the opening moment is named so the reader
     * knows when to come back, or already closed.
     */
    public static function notOnSale(?CarbonInterface $opensAt): self
    {
        if ($opensAt !== null && now()->lt($opensAt)) {
            // Spelled out in Dhaka time, not the UTC the app stores: the
            // reader is at the school, and "opens at 04:00" for a 10am
            // launch is a message that sends them away confused.
            return new self(
                'Registration for this ticket opens on '.$opensAt->copy()->timezone('Asia/Dhaka')->format('j F Y \a\t g:i A').'.',
                'not_on_sale',
            );
        }

        return new self('Registration for this ticket has closed.', 'not_on_sale');
    }

    public static function participantTypeNotAllowed(string $participantType): self
    {
        return new self(
            "This ticket is not available to the selected participant type [{$participantType}].",
            'participant_type_not_allowed',
        );
    }

    /**
     * The email belongs to an attendee reached by a different mobile number.
     *
     * A returning registrant is matched on their mobile number (ADR-08), so
     * re-using their own email is not a conflict and never reaches this.
     * What does reach it is one address being claimed by two people — which
     * has to be refused here, in the caller's own words, rather than left to
     * surface as an integrity-constraint 500 after capacity was reserved.
     */
    public static function emailAlreadyRegistered(): self
    {
        return new self(
            'This email address is already registered under a different mobile number. '
            .'Register with that number, or use another email address.',
            'email_already_registered',
        );
    }

    /**
     * This person already holds a live registration.
     *
     * One registration per attendee is the event's rule, not a technical
     * limit: a registration already admits a party through its guests, so
     * a second one is a duplicate rather than a bigger booking. Cancelled,
     * expired and refunded registrations do not count — someone whose
     * payment lapsed has to be able to start again.
     */
    public static function alreadyRegistered(?string $registrationNumber = null, ?string $status = null): self
    {
        // The existing registration is named where it is known, because the
        // two callers need different things from this message. A member of
        // the public needs to be told their booking already exists; a member
        // of staff at a desk needs to be told *which record to collect cash
        // against*, and "already has a registration" with no reference is a
        // dead end they cannot act on without going and searching for it.
        $detail = $registrationNumber !== null
            ? " (#{$registrationNumber}".($status !== null ? ", status: {$status}" : '').')'
            : '';

        return new self(
            "This mobile number already has a registration{$detail}. "
            .'Sign in to view it, or cancel it before registering again.',
            'already_registered',
        );
    }

    /**
     * More people than this ticket type admits under the event's rule.
     *
     * Names the limit, following `alreadyRegistered()`: a refusal that does
     * not say how many are allowed is one the registrant cannot act on
     * without guessing. `$limit` is the whole party, registrant included, so
     * the family half is spelled out separately — that is the number the
     * public form shows and the one the reader was counting.
     */
    public static function partyTooLarge(int $limit): self
    {
        $members = $limit - 1;

        return new self(
            $members > 0
                ? "This ticket admits at most {$limit} people including you — up to {$members} "
                    .($members === 1 ? 'family member' : 'family members').'. Remove someone and try again.'
                : 'This ticket admits one person only; family members cannot be added to it.',
            'party_too_large',
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
