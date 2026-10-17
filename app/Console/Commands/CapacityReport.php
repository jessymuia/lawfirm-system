<?php

namespace App\Console\Commands;

use App\Capacity\Probe;
use App\Capacity\Signals;
use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Cell;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/** ENG-27: each cell's scaling signals against its ceilings (docs/ops/CAPACITY.md). Exit 1 when any is WARN or FULL. */
class CapacityReport extends Command
{
    protected $signature = 'capacity:report {--cell= : one cell (multi-tenant)} {--json}';

    protected $description = 'Scaling signals of each database cell against its ceilings';

    public function handle(TenantManager $tenants, CellRegistry $cells): int
    {
        $queue = $this->queueSize();
        $reports = [];
        if (! $tenants->isMulti()) {
            $reports['this server'] = Signals::evaluate(['firms' => 1, 'capacity' => null, 'queue' => $queue] + Probe::server(DB::connection()));
        } else {
            $load = $cells->load();
            $names = array_unique([...array_keys($load), ...Cell::pluck('name')->all()]);
            foreach ($this->option('cell') ? [(string) $this->option('cell')] : $names as $name) {
                config(['database.connections.capacity_probe' => $cells->serverConfig($name) + ['database' => null]]);
                DB::purge('capacity_probe');
                try {
                    $server = Probe::server(DB::connection('capacity_probe'));
                } catch (\Throwable $e) {
                    $this->error("{$name}: unreachable ({$e->getMessage()})");
                    $server = [];
                }
                $capacity = Cell::where('name', $name)->value('capacity');
                $reports[$name] = Signals::evaluate(array_merge(array_fill_keys(['tables', 'table_open_cache', 'table_definition_cache', 'open_files_limit', 'opened_tables',
                    'uptime', 'threads_connected', 'max_used_connections', 'max_connections', 'pool_reads', 'pool_read_requests', 'bytes'], null),
                    $server, ['firms' => (int) ($load[$name] ?? 0), 'capacity' => $capacity !== null ? (int) $capacity : null, 'queue' => $queue]));
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($reports, JSON_PRETTY_PRINT));
        } else {
            foreach ($reports as $name => $rows) {
                $this->info("Cell: {$name}");
                $this->table(['signal', 'value', 'ceiling', 'state', 'action'], $rows);
            }
        }
        $bad = collect($reports)->flatten(1)->whereIn('state', ['WARN', 'FULL'])->count();

        return $bad ? self::FAILURE : self::SUCCESS;
    }

    private function queueSize(): ?int
    {
        try {
            return in_array(config('queue.connections.'.config('queue.default').'.driver'), ['sync', 'null'], true) ? 0 : (int) Queue::size();
        } catch (\Throwable) {
            return null;
        }
    }
}
