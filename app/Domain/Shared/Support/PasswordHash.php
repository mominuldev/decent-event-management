<?php

namespace App\Domain\Shared\Support;

use Illuminate\Support\Facades\Hash;
use RuntimeException;
use SensitiveParameter;

/**
 * Comparing a supplied password against a stored one, without a corrupt
 * stored value becoming a 500.
 *
 * `Hash::check()` does not return false when the *stored* hash is not one
 * the configured driver recognises — it throws:
 *
 *     if ($this->verifyAlgorithm && ! $this->isUsingCorrectAlgorithm($hashedValue)) {
 *         throw new RuntimeException('This password does not use the Bcrypt algorithm.');
 *     }
 *
 * The check is on the column, not on what the caller typed, so a row whose
 * password was written by anything other than this application — pasted
 * into phpMyAdmin, imported from another system, hashed under a driver
 * this app is no longer configured for — turns every login attempt against
 * it into an unhandled exception on a public endpoint. With APP_DEBUG on
 * that is a stack trace; with it off it is a 500 where a 401 belongs, and
 * either way it reads to the person typing as though the site is broken
 * rather than their credentials.
 *
 * A hash the hasher cannot read cannot match anything, so the honest
 * answer is false. That keeps the failure indistinguishable from a wrong
 * password, which is also the right answer for an unauthenticated caller:
 * "this account's hash is malformed" is not a fact to hand out.
 */
class PasswordHash
{
    /** A stored value this cannot read is a failed match, never an exception. */
    public static function matches(#[SensitiveParameter] string $plain, ?string $hashed): bool
    {
        if ($hashed === null || $hashed === '') {
            return false;
        }

        try {
            return Hash::check($plain, $hashed);
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * Whether this stored value could ever authenticate anyone. Asked of the
     * real hasher rather than by inspecting the string, so it stays correct
     * if the configured driver changes — the question is not "is this
     * bcrypt" but "can the hasher this app runs read it".
     */
    public static function isUsable(?string $hashed): bool
    {
        if ($hashed === null || $hashed === '') {
            return false;
        }

        try {
            Hash::check('probe', $hashed);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
