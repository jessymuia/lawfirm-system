<?php

namespace App\Enums;

/** DATA-12: how much of an invoice is paid (invoices.payment_status), always derived from its payments. */
enum PaymentStatus: string
{
    use EnumValues;

    case Unpaid = 'unpaid';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';

    public static function for(float $total, float $paid): self
    {
        return match (true) {
            $total > 0 && $paid >= $total => self::Paid,
            $paid > 0 => self::PartiallyPaid,
            default => self::Unpaid,
        };
    }
}
