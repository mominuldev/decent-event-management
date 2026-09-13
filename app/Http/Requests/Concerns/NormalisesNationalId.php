<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Registration\Support\NationalId;

/**
 * Strips an NID or birth registration number down to digits before it is
 * validated or stored.
 *
 * Shared by all four attendee write paths rather than repeated in each,
 * because the normalisation and the validation have to agree: the rule
 * accepts exactly 10, 13, 16 or 17 digits, so a request that skipped this step
 * would refuse `1234 5678 90` — a number typed the way it is printed on the
 * card — for being 12 characters long.
 *
 * A trait with a named method rather than a `prepareForValidation()` the
 * request inherits: two of the four already define that hook for the mobile
 * and email normalisation, and a trait method silently overridden by the
 * class using it is how the NID would quietly stop being normalised on
 * exactly those two paths.
 */
trait NormalisesNationalId
{
    protected function normaliseNationalIdInput(): void
    {
        if ($this->has('nid_number')) {
            $this->merge(['nid_number' => NationalId::normalise($this->input('nid_number'))]);
        }
    }
}
