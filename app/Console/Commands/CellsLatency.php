<?php

namespace App\Console\Commands;

use App\Tenancy\CellLatency;
use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Cell;
use Illuminate\Console\Command;

/**
 * TEN-4: measure what reaching a cell's database costs a request, and with --direct-host what a connection proxy in
 * front of it adds (docs/ops/CELLS.md).
 *
 *   cells:latency                                  every cell
 *   cells:latency eu-1 --direct-host=10.0.3.7      eu-1 through its proxy, and the server behind it
 *   cells:latency --host=127.0.0.1 --port=6033     an endpoint that is not (yet) a cell
 */
class CellsLatency extends Command
{
    protected $signature = 'cells:latency {cell? : one cell (default: all)} {--samples=50} {--host= : measure this endpoint instead of a cell}
        {--port=} {--direct-host= : the database server behind the cell\'s proxy, to compare} {--direct-port=}
        {--max-p95= : exit 1 if a request\'s database part takes longer than this (ms) at p95}';

    protected $description = 'Measure connection and query latency to cell databases, with and without a proxy (TEN-4)';

    public function handle(CellRegistry $cells, CellLatency $latency): int
    {
        $samples = max(5, (int) $this->option('samples'));
        $targets = [];
        if ($this->option('host')) {
            $base = $this->server($cells, (string) ($this->argument('cell') ?? 'default'));
            $targets['endpoint'] = ['host' => $this->option('host'), 'port' => $this->option('port') ?: ($base['port'] ?? 3306)] + $base;
        } else {
            try {
                $registered = Cell::query()->orderBy('name')->pluck('name')->all();
            } catch (\Throwable) {
                $registered = [];   // no control plane (a single-firm install): the one database server
            }
            $names = $this->argument('cell') ? [(string) $this->argument('cell')] : ($registered ?: ['default']);
            foreach ($names as $name) {
                $targets[$name] = $this->server($cells, $name);
            }
        }
        if ($this->option('direct-host')) {
            $first = reset($targets);
            $targets['direct'] = ['host' => $this->option('direct-host'), 'port' => $this->option('direct-port') ?: ($first['port'] ?? 3306)] + $first;
        }

        $rows = [];
        $worst = 0.0;
        $results = [];
        foreach ($targets as $name => $config) {
            $r = $latency->measure($config, $samples);
            $results[$name] = $r;
            $worst = max($worst, $r['request']['p95']);
            $where = ($config['driver'] ?? '') === 'sqlite' ? 'sqlite' : ($config['host'] ?? '?').':'.($config['port'] ?? '');
            $rows[] = [$name, $where, $r['connect']['p50'], $r['connect']['p95'], $r['query']['p50'], $r['request']['p50'], $r['request']['p95'], $r['request']['max'], $r['errors']];
        }
        $this->table(['target', 'endpoint', 'connect p50', 'connect p95', 'query p50', 'request p50', 'request p95', 'request max', 'errors'], $rows);
        $this->line("ms; {$samples} samples each; request = connect + 5 queries + disconnect.");

        if (isset($results['direct']) && count($results) >= 2) {
            $via = reset($results);
            $this->info(sprintf('Proxy cost per request: %+.3f ms at p50, %+.3f ms at p95.',
                $via['request']['p50'] - $results['direct']['request']['p50'], $via['request']['p95'] - $results['direct']['request']['p95']));
        }
        if (array_sum(array_column($results, 'errors')) > 0) {
            $this->error('Some connections failed.');

            return self::FAILURE;
        }
        if ($this->option('max-p95') !== null && $worst > (float) $this->option('max-p95')) {
            $this->error("A request's database part takes {$worst} ms at p95, over the {$this->option('max-p95')} ms limit.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function server(CellRegistry $cells, string $name): array
    {
        try {
            return $cells->serverConfig($name);
        } catch (\Throwable $e) {
            if ($name !== 'default') {
                throw $e;
            }
            // no control plane (a single-firm install): the database server the app is configured for
            $config = (array) config('database.connections.'.config('database.default'));
            unset($config['database'], $config['url']);

            return $config;
        }
    }
}
