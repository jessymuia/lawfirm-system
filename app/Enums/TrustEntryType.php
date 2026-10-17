<?php

namespace App\Enums;

/** DATA-12: money into or out of a client's trust account (trust_transactions.type). */
enum TrustEntryType: string
{
    use EnumValues;

    case Deposit = 'deposit';
    case Disbursement = 'disbursement';
}
