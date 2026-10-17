<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use Illuminate\Console\Command;

class TenantsStatus extends Command
{
    protected $signature = 'tenants:status';

    protected $description = 'Fleet view: schema-version distribution and failed tenants (TEN-7)';

    public function handle(): int
    {
        $tenants = Tenant::orderBy('slug')->get();

        $this->table(['schema version', 'status', 'tenants'], $tenants
            ->groupBy(fn ($t) => ($t->schema_version ?? '(none)').'|'.$t->migration_status)
            ->map(fn ($g, $k) => [...explode('|', $k), $g->count()])->values()->all());

        $latest = \App\Tenancy\TenantMigrator::latestVersion();
        $live = $tenants->whereNotIn('status', ['offboarded', 'purged']);
        $behind = $live->filter(fn ($t) => $t->schema_version === null || strcmp((string) $t->schema_version, (string) $latest) < 0)->count();
        $this->line("Latest migration: {$latest}");
        $this->line("{$behind} of {$live->count()} firm(s) behind; ".$live->where('migration_status', 'pending')->count().' pending, '.$live->where('migration_status', 'running')->count().' running, '.$live->where('migration_status', 'failed')->count().' failed.');

        foreach ($tenants->where('migration_status', 'failed') as $t) {
            $this->error("{$t->slug}: {$t->migration_error}");
        }

        return self::SUCCESS;
    }
}
