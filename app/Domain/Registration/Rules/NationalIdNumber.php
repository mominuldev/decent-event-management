<?php

namespace App\Domain\Registration\Rules;

use App\Domain\Registration\Support\NationalId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A Bangladeshi NID number or birth registration number, in one of the
 * real widths {@see NationalId::LENGTHS} lists.
 *
 * A rule object rather than a `regex:` string shared between four
 * FormRequests, so the widths and the message that explains them live in one
 * place — a caller told only "the nid number field format is invalid" has no
 * way to know whether their 13-digit card number is wrong or the form is.
 *
 * Expects the value to have already been normalised to digits by
 * {@see NationalId::normalise()} in the request's `prepareForValidation()`.
 * Running before normalisation would refuse a number typed with the spaces
 * that are printed on the card itself.
 */
final class NationalIdNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || ! NationalId::isWellFormed($value)) {
            $fail('The NID or birth registration number must be 10, 13, 16 or 17 digits — check the number printed on the card or certificate.');
        }
    }
}
