<?php

namespace App\Tenancy;

use App\Tenancy\Models\Tenant;
use App\Tenancy\Models\TenantDomain;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * TEN-3: resolves a tenant and points the framework at its database.
 *
 * Fail closed: in multi mode the default connection is a name that is not
 * configured, so any query made before a tenant is resolved throws instead
 * of silently hitting the control plane or another tenant.
 */
class TenantManager
{
    private ?Tenant $current = null;

    public const UNRESOLVED = 'tenant_unresolved';
    public const TENANT_CONNECTION = 'tenant';

    public function __construct(private TenantContext $context)
    {
    }

    /** The tenant resolved for this request/job, if any. */
    public function current(): ?Tenant
    {
        return $this->current;
    }

    public function isMulti(): bool
    {
        return config('tenancy.mode') === 'multi';
    }

    /**
     * TEN-17: the question other code asks instead of "which mode is this?". Platform tables (plans, firms, billing) exist only where
     * there is a control plane; on a self-hosted install there is none and the config files apply.
     */
    /** What to tell someone running fleet-only tooling on a single-firm install. */
    public const MULTI_HINT = 'This needs the multi-firm mode: set TENANCY_MODE=multi (the Docker stack does this for you).';

    public function hasControlPlane(): bool
    {
        return $this->isMulti();
    }

    /** TEN-17: tooling that only makes sense for a fleet of firms says so here, in one sentence, instead of reading the mode itself. */
    public function requireMulti(string $because): void
    {
        if (! $this->isMulti()) {
            throw new RuntimeException($because);
        }
    }

    public function findByHost(string $host): ?Tenant
    {
        $domain = TenantDomain::with('tenant')->where('domain', strtolower($host))->first();

        return $domain?->tenant;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        return Tenant::where('slug', $slug)->first();
    }

    /** Point the app (context, cache, permission cache, DB) at $tenant. */
    public function activate(Tenant $tenant, bool $allowInactive = false): void
    {
        if (! $allowInactive && ! $tenant->isActive()) {
            throw new RuntimeException("Tenant [{$tenant->slug}] is not active.");
        }

        $this->context->activate($tenant->slug);
        $this->current = $tenant;

        // TEN-4: the firm's database on its cell (template + the cell's server overrides).
        config([
            'database.connections.'.self::TENANT_CONNECTION => app(CellRegistry::class)->connectionConfig($tenant),
        ]);
        DB::purge(self::TENANT_CONNECTION);
        DB::setDefaultConnection(self::TENANT_CONNECTION);

        // TEN-2: the firm's files disk and queue come from its row, like its database
        config(['tenancy.active_files_disk' => $tenant->files_disk]);
        $this->routeQueue($tenant->queue);

        // the firm's own outgoing mail (falls back to the platform's when it has none)
        \App\Secrets\FirmMail::apply();
    }

    /** @var array{0: string, 1: mixed}|null the default queue connection and its own queue name, before any firm */
    private ?array $baseQueue = null;

    /** The lane background work goes to when no firm (or a firm without one) is active. */
    public function defaultQueue(): string
    {
        $connection = (string) config('queue.default');
        $own = $this->baseQueue !== null && $this->baseQueue[0] === $connection ? $this->baseQueue[1] : config("queue.connections.{$connection}.queue");

        return (string) ($own ?: 'default');
    }

    /**
     * TEN-2: in multi-tenant mode the database queue and the failed-job log belong to the platform (control plane), so
     * work dispatched with no firm active (fleet jobs, the console) has somewhere to go and no firm database holds
     * another firm's jobs. An explicit DB_QUEUE_CONNECTION wins.
     */
    public function useControlPlaneQueue(): void
    {
        if (! $this->isMulti()) {
            return;
        }
        $control = (string) config('tenancy.control_connection');
        if (! config('queue.connections.database.connection')) {   // DB_QUEUE_CONNECTION, when set, wins
            config(['queue.connections.database.connection' => $control]);
        }
        config(['queue.failed.database' => $control]);
    }

    /**
     * Send jobs dispatched from now on (without an explicit queue) to $queue, or back to the connection's own queue.
     * The cached queue object is dropped so the next dispatch picks the name up; the payload's connection is unchanged,
     * so `queue:retry` keeps working. Workers listen on the lanes they serve (`queue:work --queue=eu,default`).
     */
    private function routeQueue(?string $queue): void
    {
        $connection = (string) config('queue.default');
        if (! is_array(config("queue.connections.{$connection}"))) {
            return;
        }
        if ($this->baseQueue === null || $this->baseQueue[0] !== $connection) {
            $this->baseQueue = [$connection, config("queue.connections.{$connection}.queue")];
        }
        $want = $queue ?: $this->baseQueue[1];
        if (config("queue.connections.{$connection}.queue") !== $want) {
            config(["queue.connections.{$connection}.queue" => $want]);
            (fn () => $this->connections = [])->call(app('queue'));
        }
    }

    /**
     * Restore tenant context from a slug (used by queued jobs and commands).
     * In single mode there is nothing to look up: only the namespace changes.
     */
    public function activateBySlug(string $slug): void
    {
        if (! $this->isMulti()) {
            $this->context->activate($slug);

            return;
        }

        $tenant = $this->findBySlug($slug);
        if (! $tenant) {
            throw new RuntimeException("Unknown tenant [{$slug}].");
        }
        $this->activate($tenant);
    }

    /** @var list<array{0: ?Tenant, 1: string, 2: string, 3: mixed}> states saved by pushState() */
    private array $saved = [];

    /**
     * TEN-17: remember the current firm state (around a queued job). A worker saves "no firm" and gets it back after
     * each job; a job run inline by the sync queue saves the request's firm and hands it back, so the rest of that
     * request is not left fail-closed.
     */
    public function pushState(): void
    {
        $this->saved[] = [$this->current, $this->context->key(), DB::getDefaultConnection(), config('database.connections.'.self::TENANT_CONNECTION)];
    }

    public function popState(): void
    {
        $state = array_pop($this->saved);
        if ($state === null) {
            if ($this->isMulti()) {
                $this->failClosed();
            }

            return;
        }
        [$tenant, $key, $default, $connection] = $state;
        $this->context->activate($key);
        config(['database.connections.'.self::TENANT_CONNECTION => $connection]);
        DB::purge(self::TENANT_CONNECTION);
        DB::setDefaultConnection($default);
        $this->current = $tenant;
        config(['tenancy.active_files_disk' => $tenant?->files_disk]);
        if ($tenant !== null || $this->baseQueue !== null) {
            $this->routeQueue($tenant?->queue);
        }
        $tenant !== null ? \App\Secrets\FirmMail::apply() : \App\Secrets\FirmMail::reset();
    }

    /** Make every default-connection query throw until a tenant is activated. */
    public function failClosed(): void
    {
        $this->current = null;
        config(['tenancy.active_files_disk' => null]);
        if ($this->baseQueue !== null) {
            $this->routeQueue(null);
        }
        DB::setDefaultConnection(self::UNRESOLVED);
        \App\Secrets\FirmMail::reset();   // no firm: the platform's own mail
    }

    /**
     * Run $fn with $tenant active, then restore the previous connection and
     * tenant namespace (or fail closed in multi mode), even on exceptions.
     */
    public function runIn(Tenant $tenant, Closure $fn, bool $allowInactive = false): mixed
    {
        $previousDefault = DB::getDefaultConnection();
        $previousKey = $this->context->key();
        $previousTenant = $this->current;

        try {
            $this->activate($tenant, $allowInactive);

            return $fn();
        } finally {
            DB::purge(self::TENANT_CONNECTION);
            if ($this->isMulti()) {
                $this->failClosed();
            } else {
                DB::setDefaultConnection($previousDefault);
            }
            $this->context->activate($previousKey);
            $this->current = $this->isMulti() ? null : $previousTenant;
            // TEN-2: files disk and queue lane go back with the firm
            config(['tenancy.active_files_disk' => $this->current?->files_disk]);
            if ($this->baseQueue !== null) {
                $this->routeQueue($this->current?->queue);
            }
        }
    }
}
