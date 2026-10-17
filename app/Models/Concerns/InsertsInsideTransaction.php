<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * DATA-8: makes "draw the next number" and "insert the row" one atomic unit.
 *
 * Eloquent fires `creating` (where the number is drawn) at the start of
 * performInsert(). Without a surrounding transaction, the sequence counter
 * commits on its own and a later failure of the INSERT leaves a permanent gap
 * in what should be a gap-free series. Wrapping performInsert() keeps the
 * sequence row locked until the record is committed, and rolls the counter
 * back together with a failed insert.
 */
trait InsertsInsideTransaction
{
    protected function performInsert(Builder $query)
    {
        return DB::transaction(fn () => parent::performInsert($query));
    }
}
