<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** ENG-11: invoice lines (they follow their invoice). */
class InvoiceItemPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::DELETE_INVOICES);
    }

    protected function caseIdOf(Model $record): ?int
    {
        $caseId = DB::table('invoices')->where('id', $record->getAttribute('invoice_id'))->value('case_id');

        return $caseId === null ? null : (int) $caseId;
    }
}
