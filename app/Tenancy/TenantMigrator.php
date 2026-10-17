<?php

namespace App\Tenancy;

use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * TEN-7: migrates one tenant at a time and records the outcome in the control
 * plane. A failure marks that tenant `failed` (with the error) and never
 * blocks the others; re-running is idempotent because Laravel's own
 * `migrations` table in each tenant database is the source of truth.
 */
class TenantMigrator
{
    public function __construct(private TenantManager $tenants)
    {
    }

    /** Outcome of the last migrate() call: ok | failed | busy (another run holds this firm's lock; nothing was changed). */
    public ?string $lastResult = null;

    /** The newest tenant migration this release ships: a firm whose schema_version is older is behind. */
    public static function latestVersion(): ?string
    {
        $files = glob(database_path('migrations/*.php')) ?: [];
        sort($files);

        return $files ? basename((string) end($files), '.php') : null;
    }

    /** @return bool true on success */
    public function migrate(Tenant $tenant): bool
    {
        $previousDefault = DB::getDefaultConnection();
        $previousKey = app(TenantContext::class)->key();
        $lock = null;

        try {
            // Suspended/provisioning tenants must still receive schema updates.
            $this->tenants->activate($tenant, allowInactive: true);

            // TEN-7: one migration per firm at a time, whoever starts it (a worker or tenants:migrate). Taken inside the
            // firm's own cache namespace, so every caller contends on the same key.
            $lock = Cache::lock('tenant-migrate', 3600);
            if (! $lock->get()) {
                $lock = null;
                $this->lastResult = 'busy';

                return false;
            }

            $tenant->update([
                'migration_status' => 'running',
                'migration_attempted_at' => now(),
            ]);

            $exit = Artisan::call('migrate', [
                '--database' => TenantManager::TENANT_CONNECTION,
                '--force' => true,
            ]);

            if ($exit !== 0) {
                throw new \RuntimeException(trim(Artisan::output()) ?: 'migrate exited with code '.$exit);
            }

            // Permissions added by a release must reach this firm's admin role, exactly as app:upgrade does for a single install.
            if (\Illuminate\Support\Facades\Schema::hasTable('permissions') && \Illuminate\Support\Facades\Schema::hasTable('roles')) {
                \App\Support\RoleCatalog::sync();
            }
            // TEN-11: platform-owned courts / court types / hearing types are re-synced on every upgrade.
            \App\Catalog\SystemCatalog::sync();
            // DATA-9: give pre-existing records their public id; queued, so a big firm never holds up the fleet migration.
            // Only for active firms: jobs refuse inactive ones (TEN-3), a provisioning firm has nothing to fill, and any
            // row still missing an id gets one on first use (HasPublicId::publicId()). Never fails the migration.
            if ($tenant->isActive()) {
                try {
                    \App\Jobs\BackfillPublicIds::dispatch();
                    \App\Jobs\BackfillMatterParties::dispatch();   // FUN-9: past opposing parties into the conflicts register
                    \App\Jobs\BackfillDocumentText::dispatch();     // FUN-8: make earlier documents searchable
                    \App\Jobs\BackfillCaseTypes::dispatch();        // DATA-12: matters' free-text types into the case_types list
                    \App\Jobs\BackfillInvoiceFx::dispatch();       // DATA-14: issued invoices in the base currency get rate 1
                } catch (Throwable $e) {
                    report($e);
                }
            }

            $version = DB::connection(TenantManager::TENANT_CONNECTION)
                ->table('migrations')->orderByDesc('id')->value('migration');

            $tenant->update([
                'schema_version' => $version,
                'migration_status' => 'ok',
                'migration_error' => null,
            ]);
            $this->lastResult = 'ok';

            return true;
        } catch (Throwable $e) {
            $tenant->update([
                'migration_status' => 'failed',
                'migration_error' => mb_substr($e->getMessage(), 0, 4000),
            ]);
            $this->lastResult = 'failed';

            return false;
        } finally {
            $lock?->release();
            // Never leave the process pointing at a tenant database.
            DB::purge(TenantManager::TENANT_CONNECTION);
            if ($this->tenants->isMulti()) {
                $this->tenants->failClosed();
            } else {
                DB::setDefaultConnection($previousDefault);
            }
            app(TenantContext::class)->activate($previousKey);
        }
    }
}
