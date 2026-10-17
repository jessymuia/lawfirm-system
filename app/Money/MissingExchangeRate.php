<?php

namespace App\Money;

use RuntimeException;

/** DATA-14: an invoice in another currency cannot be issued until the firm has a rate for it on or before its date. */
class MissingExchangeRate extends RuntimeException
{
    public function __construct(public readonly string $currency, public readonly string $base, public readonly string $date)
    {
        parent::__construct("There is no {$currency} to {$base} exchange rate on or before {$date}. Add one under Settings → Currencies and tax, then issue the invoice.");
    }
}
