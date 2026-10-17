<?php

namespace App\Billing;

/** Integer minor-unit arithmetic. No floats anywhere near money. */
class Money
{
    /** round-half-up of $amount * $num / $den, in integers */
    public static function ratio(int $amount, int $num, int $den): int
    {
        if ($den <= 0) {
            throw new BillingException('Zero-length period.');
        }
        $sign = $amount < 0 ? -1 : 1;
        $a = abs($amount);

        return $sign * intdiv($a * $num * 2 + $den, 2 * $den);
    }

    /** tax in minor units for a net amount, from basis points (round half up) */
    public static function tax(int $net, int $bp): int
    {
        return $net <= 0 || $bp <= 0 ? 0 : self::ratio($net, $bp, 10000);
    }
}
