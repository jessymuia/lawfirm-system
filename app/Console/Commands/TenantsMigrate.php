<?php

namespace App\Console\Commands;

use App\Jobs\MigrateTenant;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantMigrator;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class TenantsMigrate extends Command
{
    protected $signature = 'tenants:migrate
        {--tenant= : Only this tenant slug}
        {--retry-failed : Only tenants whose last migration failed}
        {--resume : Only tenants not yet on this release: pending, failed, stuck running, or behind the latest migration}
        {--queue : Queue one job per firm (workers migrate in parallel) instead of migrating here, one by one}';

    protected $description = 'Run pending migrations on every tenant database (TEN-7)';

    /** A `running` firm whose attempt is older than this is treated as stuck (the worker died) by --resume. */
    private const STALE_MINUTES = 90;

    public function handle(TenantMigrator $migrator): int
    {
        $query = Tenant::whereNotIn('status', ['offboarded', 'purged'])->orderBy('slug');

        if ($slug = $this->option('tenant')) {
            $query->where('slug', $slug);
        }
        if ($this->option('retry-failed')) {
            $query->where('migration_status', 'failed');
        }
        if ($this->option('resume')) {
            $latest = TenantMigrator::latestVersion();
            $query->where(fn (Builder $q) => $q
                ->whereIn('migration_status', ['pending', 'failed'])
                ->orWhere(fn (Builder $q) => $q->where('migration_status', 'running')
                    ->where(fn (Builder $q) => $q->whereNull('migration_attempted_at')->orWhere('migration_attempted_at', '<', now()->subMinutes(self::STALE_MINUTES))))
                ->orWhereNull('schema_version')
                ->orWhere('schema_version', '<', (string) $latest));
        }

        $tenants = $query->get();
        if ($tenants->isEmpty()) {
            $this->warn('No matching tenants.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            foreach ($tenants as $tenant) {
                // `pending` until a worker picks it up and the migrator marks it `running`; a firm already queued is
                // not queued twice (MigrateTenant is unique per firm).
                if ($tenant->migration_status !== 'running') {
                    $tenant->update(['migration_status' => 'pending']);
                }
                MigrateTenant::dispatch($tenant->slug);
            }
            $this->info($tenants->count().' firm(s) queued. Follow progress with `php artisan tenants:status`.');

            return self::SUCCESS;
        }

        $counts = ['ok' => 0, 'failed' => 0, 'busy' => 0];
        foreach ($tenants as $tenant) {
            $migrator->migrate($tenant);
            $result = $migrator->lastResult ?? 'failed';
            $counts[$result]++;
            match ($result) {
                'ok' => $this->info("{$tenant->slug}: ok ({$tenant->fresh()->schema_version})"),
                'busy' => $this->warn("{$tenant->slug}: skipped, another migration of this firm is running"),
                default => $this->error("{$tenant->slug}: FAILED - ".$tenant->fresh()->migration_error),
            };
        }

        $this->line("{$counts['ok']} ok, {$counts['failed']} failed, {$counts['busy']} busy.");

        return $counts['failed'] ? self::FAILURE : self::SUCCESS;
    }
}
