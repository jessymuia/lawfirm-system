<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/**
 * DATA-14: seen by whoever sees invoices; changed by whoever manages the firm's money settings.
 * Tax rates are archived, never deleted (lines point at them).
 */
class TaxRatePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_INVOICES, P::MANAGE_FINANCE_SETTINGS, P::MANAGE_FINANCE_SETTINGS, null);
    }
}
