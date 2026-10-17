<?php

namespace App\Policies;

use App\Constants\ModulePermissions as P;
use Illuminate\Database\Eloquent\Model;

/** ENG-11: payments (append-only: no update; delete reverses). */
class PaymentPolicy extends ModulePolicy
{
    protected function permissions(): array
    {
        return self::crud(P::LIST_PAYMENTS, P::CREATE_PAYMENTS, null, P::DELETE_PAYMENTS);
    }

    protected function caseIdOf(Model $record): ?int
    {
        $caseId = \Illuminate\Support\Facades\DB::table('invoices')->where('id', $record->getAttribute('invoice_id'))->value('case_id');

        return $caseId === null ? null : (int) $caseId;
    }
}
