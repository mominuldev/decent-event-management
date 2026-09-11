<?php

namespace App\Http\Requests\Attendee;

/**
 * The profile update a passwordless "find my ticket" session may make.
 *
 * Identical to {@see UpdateProfileRequest} minus `email`, and that one
 * omission is the whole reason this class exists rather than the parent
 * being reused directly.
 *
 * The lookup credential is mobile-or-email plus the registered name, which
 * is guessable in a way a password is not. Everything else on the profile —
 * father's name, address, occupation, t-shirt size, blood group — is a fact
 * about the person, and getting one wrong costs an incorrect record. The
 * email address is not that: it is one of the two identifiers this very
 * lookup matches on, and it is where the ticket confirmation is delivered.
 * A caller who guessed their way in and could repoint it would have turned
 * a weak read into a resend of somebody else's ticket to an inbox they own.
 *
 * `mobile` needs no such handling — the parent never accepted it, because
 * it is the sign-in channel. An attendee who genuinely needs either
 * identifier changed goes through staff, who are attributed for it.
 */
class UpdateLookupProfileRequest extends UpdateProfileRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        // Dropped from the ruleset, not merely ignored downstream:
        // `validated()` returns only what a rule named, so the controller
        // cannot apply a key that is not here even by accident.
        unset($rules['email']);

        return $rules;
    }
}
