<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;

/**
 * DATA-14: seen by whoever sees invoices; changed by whoever manages the firm's money settings.
 * Currencies are disabled, never deleted.
 */
class CurrencyPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_INVOICES, P::MANAGE_FINANCE_SETTINGS, P::MANAGE_FINANCE_SETTINGS, null);
    }
}
