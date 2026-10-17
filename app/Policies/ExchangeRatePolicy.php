<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/**
 * DATA-14: seen by whoever sees invoices; changed by whoever manages the firm's money settings.
 * A rate entered by mistake can be deleted: issued invoices keep the rate they fixed.
 */
class ExchangeRatePolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_INVOICES, P::MANAGE_FINANCE_SETTINGS, P::MANAGE_FINANCE_SETTINGS, P::MANAGE_FINANCE_SETTINGS);
    }
}
