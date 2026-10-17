<?php

namespace App\Status;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * PLAT-12: health checks for one cell, the probe history they leave, and availability computed from that history.
 *
 * Probes run from INSIDE the deployment (a scheduled command), so they see what the application sees. They cannot see an outage
 * of the network in front of it; an external monitor on /health/ready covers that (docs/STATUS.md).
 * Failure details are fixed phrases: exception text can carry hosts and credentials and this data is shown publicly.
 */
class Health
{
    public const OK = 'operational';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    public const UNKNOWN = 'unknown';

    public function __construct(private TenantManager $tenants) {}

    /** The platform's own store: the control plane in multi mode, the one database otherwise. Never the request's tenant connection. */
    public function store()
    {
        return DB::connection($this->tenants->isMulti() ? config('tenancy.control_connection') : config('database.default'));
    }

    /** @return string[] components that are measured by probes */
    public function probed(): array
    {
        return array_merge(['database', 'cache', 'storage', 'queue'], $this->tenants->isMulti() ? ['tenant_databases'] : []);
    }

    /** @return string[] everything shown on the status page */
    public function components(): array
    {
        return array_merge($this->probed(), ['scheduler']);
    }

    public function label(string $c): string
    {
        return (string) config("status.labels.{$c}", $c);
    }

    // ---- checks ----------------------------------------------------------------------------------------------------

    /** @return array{0:string,1:int,2:?string} status, latency in ms, fixed-phrase detail */
    public function check(string $component): array
    {
        $t = hrtime(true);
        try {
            [$status, $detail] = match ($component) {
                'database' => $this->database(),
                'cache' => $this->cache(),
                'storage' => $this->storage(),
                'queue' => $this->queue(),
                'tenant_databases' => $this->tenantDatabases(),
                default => throw new \InvalidArgumentException("unknown component {$component}"),
            };
        } catch (Throwable $e) {
            report($e);
            [$status, $detail] = [self::DOWN, 'check failed'];
        }

        return [$status, (int) round((hrtime(true) - $t) / 1e6), $detail];
    }

    private function database(): array
    {
        $this->store()->select('select 1');

        return [self::OK, null];
    }

    private function cache(): array
    {
        $k = 'health:'.Str::random(12);
        Cache::put($k, $k, 30);
        $ok = Cache::get($k) === $k;
        Cache::forget($k);

        return $ok ? [self::OK, null] : [self::DOWN, 'read-back mismatch'];
    }

    private function storage(): array
    {
        $disk = Storage::disk(config('status.storage_disk') ?: null);
        $p = '.health/'.Str::random(12);
        $disk->put($p, 'ok');
        $ok = $disk->get($p) === 'ok';
        $disk->delete($p);

        return $ok ? [self::OK, null] : [self::DOWN, 'read-back mismatch'];
    }

    private function queue(): array
    {
        $driver = config('queue.connections.'.config('queue.default').'.driver');
        if (in_array($driver, ['sync', 'null'], true)) {
            return [self::OK, 'runs inline'];
        }
        if ($driver === 'database' && $this->tenants->isMulti()) {
            return [self::UNKNOWN, 'not measured']; // the jobs table lives with the firms, not on the control plane
        }
        $size = (int) Queue::size();

        return $size > (int) config('status.queue_backlog_degraded') ? [self::DEGRADED, 'backlog'] : [self::OK, null];
    }

    /** multi mode: a random sample of active firms' databases. */
    private function tenantDatabases(): array
    {
        $sample = Tenant::where('status', 'active')->inRandomOrder()->limit((int) config('status.tenant_sample'))->get();
        if ($sample->isEmpty()) {
            return [self::OK, 'no firms'];
        }
        $bad = $sample->filter(fn ($t) => ! $this->tenantReachable($t))->count();

        return match (true) {
            $bad === 0 => [self::OK, null],
            $bad === $sample->count() => [self::DOWN, "{$bad}/{$sample->count()} unreachable"],
            default => [self::DEGRADED, "{$bad}/{$sample->count()} unreachable"],
        };
    }

    public function tenantReachable(Tenant $t): bool
    {
        try {
            $this->tenants->runIn($t, fn () => DB::select('select 1'));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    // ---- probing ---------------------------------------------------------------------------------------------------

    /** Run every probed check and record it. @return array<string,array{0:string,1:int,2:?string}> */
    public function probe(): array
    {
        $now = now();
        $out = [];
        foreach ($this->probed() as $c) {
            $out[$c] = $this->check($c);
        }
        $this->store()->table('health_probes')->insert(array_map(
            fn ($c, $r) => ['component' => $c, 'status' => $r[0], 'latency_ms' => $r[1], 'detail' => $r[2], 'probed_at' => $now],
            array_keys($out),
            $out,
        ));

        return $out;
    }

    public function prune(): int
    {
        return $this->store()->table('health_probes')->where('probed_at', '<', now()->subDays((int) config('status.keep_days')))->delete();
    }

    // ---- readiness / current state ---------------------------------------------------------------------------------

    /** Live check of what a request needs (no history written). @return array{status:string,components:array<string,string>} */
    public function ready(): array
    {
        $c = [];
        foreach (config('status.critical') as $name) {
            $c[$name] = $this->check($name)[0];
        }

        return ['status' => $this->overall($c), 'components' => $c];
    }

    /** @param array<string,string> $c */
    public function overall(array $c): string
    {
        $critical = (array) config('status.critical');
        $worst = self::UNKNOWN;
        foreach ($c as $name => $s) {
            if ($s === self::DOWN) {
                if (in_array($name, $critical, true)) {
                    return self::DOWN;
                }
                $s = self::DEGRADED;   // a non-critical part down is a degraded service, not an outage
            }
            $rank = [self::UNKNOWN => 0, self::OK => 1, self::DEGRADED => 2];
            if (($rank[$s] ?? 0) > $rank[$worst]) {
                $worst = $s;
            }
        }

        return $worst;
    }

    /** Latest recorded state per component; anything not probed recently is "unknown", and a silent scheduler is "down". */
    public function current(): array
    {
        $db = $this->store();
        $stale = now()->subMinutes((int) config('status.stale_after_minutes'));
        $out = [];
        $newest = null;
        foreach ($this->probed() as $c) {
            $row = $db->table('health_probes')->where('component', $c)->orderByDesc('probed_at')->orderByDesc('id')->first();
            $at = $row ? Carbon::parse($row->probed_at) : null;
            $newest = $at && (! $newest || $at->gt($newest)) ? $at : $newest;
            $fresh = $at && $at->gte($stale);
            $out[$c] = ['status' => $fresh ? $row->status : self::UNKNOWN, 'latency_ms' => $fresh ? (int) $row->latency_ms : null, 'detail' => $fresh ? $row->detail : 'no recent measurement', 'checked_at' => $at?->toIso8601String()];
        }
        $beat = $newest && $newest->gte($stale);
        $out['scheduler'] = ['status' => $beat ? self::OK : self::DOWN, 'latency_ms' => null, 'detail' => $beat ? null : 'no recent probe', 'checked_at' => $newest?->toIso8601String()];

        return $out;
    }

    // ---- availability from real probes -----------------------------------------------------------------------------

    /**
     * Measured availability per probed component over the last $days days (capped), from recorded probes only.
     * Probes inside a declared maintenance window that covers the component are excluded. "degraded" counts as available;
     * "unknown" is not measured. `coverage` is how much of the window actually has probes: gaps (scheduler down, deployment
     * not yet running) are NOT counted as available or unavailable, so read availability together with coverage.
     *
     * @return array<string,array{availability:?float,measured_minutes:int,down_minutes:int,degraded_minutes:int,maintenance_minutes_excluded:int,coverage:float}>
     */
    public function availability(int $days = 30): array
    {
        $days = max(1, min($days, (int) config('status.max_window_days')));
        $from = now()->subDays($days);
        $to = now();
        $step = (int) config('status.probe_interval_minutes');
        $db = $this->store();
        $first = $db->table('health_probes')->min('probed_at');
        $effectiveFrom = $first ? Carbon::parse($first)->max($from) : $from;
        $expected = max(1, (int) floor($effectiveFrom->diffInMinutes($to) / $step));
        $windows = $this->maintenanceWindows($from, $to);

        $out = [];
        foreach ($this->probed() as $c) {
            $count = fn ($q) => $q->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
            $total = $count($db->table('health_probes')->where('component', $c)->where('probed_at', '>=', $from));
            $excluded = ['operational' => 0, 'degraded' => 0, 'down' => 0, 'unknown' => 0];
            foreach ($this->mergeWindows($windows, $c) as [$a, $b]) {
                foreach ($count($db->table('health_probes')->where('component', $c)->where('probed_at', '>=', $a)->where('probed_at', '<=', $b)) as $s => $n) {
                    $excluded[$s] = ($excluded[$s] ?? 0) + $n;
                }
            }
            $n = fn ($s) => max(0, (int) ($total[$s] ?? 0) - $excluded[$s]);
            $measured = $n('operational') + $n('degraded') + $n('down');
            $present = array_sum(array_map('intval', $total->all()));
            $out[$c] = [
                'availability' => $measured ? round(($measured - $n('down')) / $measured * 100, 3) : null,
                'measured_minutes' => $measured * $step, 'down_minutes' => $n('down') * $step, 'degraded_minutes' => $n('degraded') * $step,
                'maintenance_minutes_excluded' => (int) array_sum($excluded) * $step,
                'coverage' => round(min(100, $present / $expected * 100), 1),
            ];
        }

        return $out;
    }

    /** @return array<int,array{0:Carbon,1:Carbon,2:array}> */
    private function maintenanceWindows($from, $to): array
    {
        $w = [];
        foreach ($this->store()->table('status_incidents')->where('impact', 'maintenance')->where('started_at', '<=', $to)->get() as $i) {
            $a = Carbon::parse($i->started_at);
            $b = $i->resolved_at ? Carbon::parse($i->resolved_at) : ($i->ends_at ? Carbon::parse($i->ends_at) : $to);
            if ($b->lt($from) || $b->lte($a)) {
                continue;
            }
            $w[] = [$a->max($from), $b->min($to), (array) json_decode((string) $i->components, true)];
        }

        return $w;
    }

    /** Windows that cover $component, overlapping ones merged so no probe is excluded twice. */
    private function mergeWindows(array $windows, string $component): array
    {
        $mine = array_values(array_filter($windows, fn ($w) => $w[2] === [] || in_array($component, $w[2], true)));
        usort($mine, fn ($x, $y) => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($mine as [$a, $b]) {
            if ($merged && $a->lte(end($merged)[1])) {
                $merged[count($merged) - 1][1] = end($merged)[1]->max($b);
            } else {
                $merged[] = [$a, $b];
            }
        }

        return $merged;
    }
}
