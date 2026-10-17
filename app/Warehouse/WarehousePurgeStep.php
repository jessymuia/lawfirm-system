<?php

namespace App\Warehouse;

use App\Tenancy\Models\Tenant;
use App\Tenancy\PurgeStep;

/** ENG-26 / TEN-9: deleting a firm expunges its rows from the warehouse too. */
class WarehousePurgeStep implements PurgeStep
{
    public function name(): string
    {
        return 'warehouse';
    }

    public function purge(Tenant $tenant): string
    {
        if (! config('warehouse.enabled')) {
            return 'none: no analytics warehouse holds firm rows (ENG-26 not enabled)';
        }
        $n = 0;
        foreach (WarehouseExporter::sinks() as $sink) {
            $n += $sink->purge($tenant->slug);
        }

        return "expunged ({$n} rows/files)";
    }
}
