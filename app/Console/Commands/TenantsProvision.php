<?php

namespace App\Console\Commands;

use App\Tenancy\TenantLifecycle;
use Illuminate\Console\Command;
use Throwable;

class TenantsProvision extends Command
{
    protected $signature = 'tenants:provision {slug}
        {--name= : Firm name (defaults to the slug)}
        {--admin-email= : First administrator email (required)}
        {--admin-name=Administrator}
        {--admin-phone= : Admin phone number}
        {--domain= : Custom domain (default <slug>.TENANCY_BASE_DOMAIN)}
        {--region=} {--plan=} {--cell= : TEN-4: put the firm in this cell instead of the least-loaded one}';

    protected $description = 'Create a tenant: database, schema, catalog, first admin (TEN-9). Safe to re-run if it failed midway.';

    public function handle(TenantLifecycle $lifecycle): int
    {
        if (! $this->option('admin-email')) {
            $this->error('--admin-email is required.');

            return self::FAILURE;
        }

        try {
            $result = $lifecycle->provision([
                'slug' => $this->argument('slug'),
                'name' => $this->option('name') ?: $this->argument('slug'),
                'admin_email' => $this->option('admin-email'),
                'admin_name' => $this->option('admin-name'),
                'admin_phone' => (string) $this->option('admin-phone'),
                'domain' => $this->option('domain'),
                'region' => $this->option('region'),
                'plan' => $this->option('plan'),
                'cell' => $this->option('cell'),
            ]);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Tenant [{$result['tenant']->slug}] is active.");
        if ($result['admin_password']) {
            $this->warn("Initial admin password (shown once): {$result['admin_password']}");
        }

        return self::SUCCESS;
    }
}
