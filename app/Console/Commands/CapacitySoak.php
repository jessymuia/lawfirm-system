<?php

namespace App\Console\Commands;

use App\Capacity\Soak;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** ENG-27: measure a database server's ceiling for firm databases (docs/ops/CAPACITY.md). Never on a production server. */
class CapacitySoak extends Command
{
    protected $signature = 'capacity:soak {--firms=200} {--step=50} {--requests=300 : requests measured per step} {--connection= : default: the app\'s}
        {--keep : leave the scratch databases} {--force : run although APP_ENV is production} {--json : print the results as JSON}';

    protected $description = 'Load a database server with scratch firm databases and measure how it degrades';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This creates and drops hundreds of databases: run it on a staging copy of a cell, not production (--force to override).');

            return self::FAILURE;
        }
        $db = DB::connection($this->option('connection') ?: null);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->error('capacity:soak measures MySQL/MariaDB servers.');

            return self::FAILURE;
        }
        $soak = new Soak($db);
        $soak->keep = (bool) $this->option('keep');
        $rows = $soak->run(max(1, (int) $this->option('firms')), max(1, (int) $this->option('step')), max(10, (int) $this->option('requests')),
            fn ($r) => $this->option('json') ?: $this->line(implode("\t", $r)));
        if ($this->option('json')) {
            $this->line((string) json_encode(['server' => $db->selectOne('select version() as v')->v, 'results' => $rows], JSON_PRETTY_PRINT));
        } else {
            $this->table(array_keys($rows[0] ?? []), $rows);
        }

        return self::SUCCESS;
    }
}
