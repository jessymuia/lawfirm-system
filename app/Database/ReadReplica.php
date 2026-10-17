<?php

namespace App\Database;

use App\Tenancy\CellRegistry;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ENG-23: heavy reads (partner reports, the dashboard) go to a read replica when one is configured, so they never load
 * the database that takes the writes.
 *
 * Where the replica is:
 *  - REPORTS_DB_CONNECTION names a connection outright (a single-firm install with its own replica connection);
 *  - otherwise the replica is the connection reads would use anyway, on another host: the firm's cell `replica_host`
 *    (multi-firm, TEN-4; the same database name on the cell's replica), or DB_REPLICA_HOST for a single firm and for
 *    the template-only default cell.
 *
 * A replica is used only while it is reachable, replicating, and no further behind than `database.replica.max_lag`;
 * otherwise the read goes to the primary, so a report is never wrong, only sometimes heavier. How far behind the
 * figures are is known to the caller (`last()`), and the screens show it (X-Data-Lag).
 */
class ReadReplica
{
    public const CONNECTION = 'replica';

    /** Health answers are kept this long (seconds), so a burst of report requests checks the replica once. */
    private const RECHECK = 30;

    /** @var array<string, array{0: bool, 1: ?int, 2: int}> signature => [usable, lag seconds, checked at] */
    private array $health = [];

    /** @var array{source: string, lag_seconds: ?int}|null */
    private ?array $last = null;

    /** Run $fn with the replica as the default connection (or the primary, when the replica is absent or unfit). */
    public function read(Closure $fn): mixed
    {
        $name = $this->usable();
        if ($name === null) {
            $this->last = ['source' => 'primary', 'lag_seconds' => null];

            return $fn();
        }
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($name);
        try {
            return $fn();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /** Where the last read() went, and how far behind its figures were (null = up to date, or not known). */
    public function last(): ?array
    {
        return $this->last;
    }

    /** The replica's connection config for the current firm, or null when it has none. */
    public function config(): ?array
    {
        if ($named = config('reports.connection')) {
            return is_array($c = config("database.connections.{$named}")) ? $c : null;
        }
        $base = config('database.connections.'.DB::getDefaultConnection());
        $over = $this->overrides();

        return is_array($base) && $over ? array_merge($base, $over) : null;
    }

    /** @return array<string, mixed>|null host (and port, credentials) of the replica of the current connection */
    private function overrides(): ?array
    {
        $env = array_filter((array) config('database.replica'), fn ($v, $k) => in_array($k, ['host', 'port', 'username', 'password'], true) && $v !== null && $v !== '', ARRAY_FILTER_USE_BOTH);
        $tenant = app(TenantManager::class)->current();
        if (! $tenant) {
            return isset($env['host']) ? $env : null;   // one firm: the environment names its replica
        }
        $cell = app(CellRegistry::class)->find($tenant->cell ?: 'default');
        if ($cell?->replica_host) {
            return array_filter(['host' => $cell->replica_host, 'port' => $cell->replica_port], fn ($v) => $v !== null);
        }

        // the environment's replica belongs to the template's server: a cell on another host needs its own
        return isset($env['host']) && ! $cell?->host ? $env : null;
    }

    /** The connection name to read from, or null for the primary. */
    private function usable(): ?string
    {
        $config = $this->config();
        if ($config === null) {
            return null;
        }
        $name = config('reports.connection') ?: self::CONNECTION;
        if ($name === self::CONNECTION && config('database.connections.'.self::CONNECTION) !== $config) {
            config(['database.connections.'.self::CONNECTION => $config]);   // another firm's replica: reconnect
            DB::purge(self::CONNECTION);
        }

        $key = sha1(json_encode([$config['driver'] ?? '', $config['host'] ?? '', $config['port'] ?? '', $config['database'] ?? '']));
        $h = $this->health[$key] ?? null;
        if ($h === null || time() - $h[2] >= self::RECHECK) {
            $h = $this->health[$key] = [...$this->check(DB::connection($name)), time()];
        }
        $this->last = ['source' => $h[0] ? 'replica' : 'primary', 'lag_seconds' => $h[0] ? $h[1] : null];

        return $h[0] ? $name : null;
    }

    /** @return array{0: bool, 1: ?int} usable, seconds behind */
    private function check(Connection $c): array
    {
        try {
            $c->select('select 1');
            $lag = $this->measureLag($c);
        } catch (\Throwable $e) {
            Log::warning('Read replica unreachable; reading from the primary.', ['error' => $e->getMessage()]);

            return [false, null];
        }
        if ($lag === false) {
            Log::warning('Read replica is not replicating; reading from the primary.');

            return [false, null];
        }
        if ($lag !== null && $lag > (int) config('database.replica.max_lag', 300)) {
            Log::notice("Read replica is {$lag}s behind; reading from the primary.");

            return [false, null];
        }

        return [true, $lag];
    }

    /**
     * Seconds the replica is behind; false when replication has stopped; null when it cannot say (not MySQL/MariaDB,
     * or the account may not read replication status, or it is not a replica at all).
     */
    protected function measureLag(Connection $c): int|false|null
    {
        if (! in_array($c->getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }
        foreach (['SHOW REPLICA STATUS', 'SHOW SLAVE STATUS'] as $sql) {   // MySQL 8.0.22+ / MariaDB and older MySQL
            try {
                $row = $c->selectOne($sql);
            } catch (\Throwable) {
                continue;
            }
            if (! $row) {
                return null;
            }
            $row = (array) $row;
            $lag = $row['Seconds_Behind_Source'] ?? $row['Seconds_Behind_Master'] ?? null;

            return $lag === null ? false : (int) $lag;
        }

        return null;
    }
}
