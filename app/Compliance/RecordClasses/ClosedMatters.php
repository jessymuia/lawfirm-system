<?php

namespace App\Compliance\RecordClasses;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** SEC-13: shared by the record classes whose clock is the end of a closed or settled matter. */
trait ClosedMatters
{
    /** Matters closed or settled on or before $cutoff. */
    private function endedBy(string $cutoff): Builder
    {
        return DB::table('cases')->whereIn('lifecycle_status', ['closed', 'settled'])->whereNotNull('end_date')->where('end_date', '<=', $cutoff)->select('id');
    }

    /** Ids of records of this type already disposed of. */
    private function disposed(): Builder
    {
        return DB::table('retention_disposals')->where('record_type', $this->type())->whereNotNull('record_id')->select('record_id');
    }
}
