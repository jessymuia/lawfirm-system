<?php

namespace App\Enums;

/** DATA-12: how money was received (payments.method). */
enum PaymentMethod: string
{
    use EnumValues;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case MobileMoney = 'mobile_money';
    case Cheque = 'cheque';
    case Card = 'card';
    case Other = 'other';
}
