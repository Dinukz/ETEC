<?php

namespace App\Rules;

use App\Support\EmailAddressCheck;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Refuses an email address that is certainly mistyped — see EmailAddressCheck
 * (2026-11-10: Hostinger suspended the site's mailbox over the bounces to them).
 * Use alongside the plain 'email' rule; this one only adds what that misses.
 */
class RealEmailAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && ($problem = EmailAddressCheck::problem($value)) !== null) {
            $fail($problem);
        }
    }
}
