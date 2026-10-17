<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;
use Illuminate\Console\Command;
use Throwable;

/** Shared behaviour for the small tenants:* lifecycle commands (TEN-9). */
abstract class TenantsLifecycleCommand extends Command
{
    abstract protected function apply(TenantLifecycle $lifecycle, Tenant $tenant): string;

    public function handle(TenantLifecycle $lifecycle): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();
        if (! $tenant) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        try {
            $this->info($this->apply($lifecycle, $tenant));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
