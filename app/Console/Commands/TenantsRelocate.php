<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantRelocator;
use Illuminate\Console\Command;

/** TEN-16: move a firm's database to another cell with a brief read-only window. */
class TenantsRelocate extends Command
{
    protected $signature = 'tenants:relocate {slug} {--to= : target cell} {--drop-old : drop the old database once the move succeeded}
        {--ignore-capacity : move even into a full or non-accepting cell}';

    protected $description = 'Move a firm to another cell (read-only during the copy; rolled back on any failure)';

    public function handle(TenantRelocator $relocator): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();
        if (! $tenant || ! $this->option('to')) {
            $this->error($tenant ? '--to=<cell> is required.' : 'No such tenant.');

            return self::FAILURE;
        }
        try {
            $r = $relocator->relocate($tenant, (string) $this->option('to'), (bool) $this->option('drop-old'), (bool) $this->option('ignore-capacity'));
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Moved [{$tenant->slug}] from [{$r['from']}] to [{$r['to']}] ({$r['tables']} tables verified).");
        $this->line($r['old_dropped'] ? 'The old database was dropped.' : "The old database [{$r['old_database']}] on [{$r['from']}] was kept as a rollback; drop it when satisfied.");

        return self::SUCCESS;
    }
}
