<?php

namespace App\Rules;

use App\Money\Money;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** DATA-14: one of the currencies the firm invoices in, read when validating (not when the rules are built). */
class FirmCurrency implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! in_array(strtoupper((string) $value), app(Money::class)->currencies(), true)) {
            $fail('The :attribute must be one of the firm\'s currencies (Settings → Currencies and tax).');
        }
    }
}
