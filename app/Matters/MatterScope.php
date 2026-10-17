<?php

namespace App\Matters;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;

/**
 * SEC-10: hides rows that belong to matters the current user cannot access, in every Eloquent query: list, show (route
 * binding yields 404, so existence is not leaked), search, export, dashboard counts, relations.
 *
 *   new MatterScope('id')                        the matter itself (cases)
 *   new MatterScope('case_id')                   rows that carry a case_id; NULL = firm-level row, visible
 *   new MatterScope('invoice_id', 'invoices')    rows reached through a parent that carries case_id
 */
class MatterScope implements Scope
{
    public function __construct(private string $column, private ?string $viaTable = null) {}

    public function apply(Builder $builder, Model $model): void
    {
        $user = MatterAccess::subject();
        if (! $user) {
            return;
        }
        $col = $model->qualifyColumn($this->column);
        $visible = MatterAccess::visibleCaseIds($user);

        if ($this->viaTable) {
            $parent = DB::table($this->viaTable)->select($this->viaTable.'.id')
                ->where(fn ($q) => $q->whereNull($this->viaTable.'.case_id')->orWhereIn($this->viaTable.'.case_id', $visible));
            $builder->whereIn($col, $parent);

            return;
        }

        if ($this->column === 'id') {
            $builder->whereIn($col, $visible);

            return;
        }

        $builder->where(fn ($q) => $q->whereNull($col)->orWhereIn($col, $visible));
    }
}
