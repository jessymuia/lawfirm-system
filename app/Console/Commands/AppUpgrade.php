<?php

namespace App\Console\Commands;

use App\Catalog\SystemCatalog;
use App\Jobs\BackfillCaseTypes;
use App\Jobs\BackfillDocumentText;
use App\Jobs\BackfillInvoiceFx;
use App\Jobs\BackfillMatterParties;
use App\Jobs\BackfillPublicIds;
use App\Releases\ReleaseNotes;
use App\Services\Migration\LegacyMapping;
use App\Support\ReleaseLog;
use App\Support\RoleCatalog;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLAT-6: the one command that brings an installation up to the code's release. Idempotent.
 *
 *   preflight -> downgrade guard -> [backup] -> [maintenance on] -> migrate (control + tenants)
 *   -> permission/role catalog sync -> system reference data sync (TEN-11) -> cache rebuild -> queue:restart -> record release
 *
 * Migrations are forward-only and expand/contract (docs/MIGRATION_POLICY.md), so the previous
 * release keeps working against the upgraded schema; that is what makes rollback a code switch.
 */
class AppUpgrade extends Command
{
    protected $signature = 'app:upgrade
        {--dry-run : Report what would change; change nothing}
        {--backup : Run backup:run before migrating}
        {--maintenance : Put the app in maintenance mode while migrating (always lifted afterwards)}
        {--allow-downgrade : Proceed although the recorded release is newer than this code}
        {--skip-cache : Do not rebuild config/route/view/event caches}
        {--accept-legacy-values : Upgrade a legacy database although it holds values missing from config/legacy_mapping.php (CARRY-4; they take the upgrade defaults)}';

    protected $description = 'Idempotent upgrade: migrate, re-sync permissions/roles, rebuild caches, record the release';

    public function handle(): int
    {
        $version = (string) config('app.version');
        $multi = config('tenancy.mode') === 'multi';
        $dry = (bool) $this->option('dry-run');

        if ($problems = $this->preflight()) {
            foreach ($problems as $p) {
                $this->error("Preflight failed: {$p}");
            }

            return self::FAILURE;
        }

        $recorded = ReleaseLog::current();
        if ($recorded !== null) {
            $risk = ReleaseLog::downgradeRisk($recorded, $version);
            if ($risk === 'block' && ! $this->option('allow-downgrade')) {
                $this->error("Refusing to run: this installation was last upgraded by release {$recorded}, more than one minor release newer than this code ({$version}).");
                $this->line('Only the previous minor release is guaranteed to work on a newer schema (docs/MIGRATION_POLICY.md).');
                $this->line('Redeploy the newer release, restore a backup taken before the upgrade, or pass --allow-downgrade if you accept the risk.');

                return self::FAILURE;
            }
            if ($risk !== 'none') {
                $this->warn("Running {$version} against a schema last upgraded by {$recorded}: supported rollback window (previous minor).");
            }
        }

        $pending = $this->pendingMigrations($multi);
        $this->line('Release: '.($recorded ?? 'none recorded')." -> {$version}");
        foreach (ReleaseNotes::fromApp()->summaryLines($recorded, $version) as $l) {   // PLAT-10
            $this->line($l);
        }
        $this->line($pending === [] ? 'Pending migrations: none' : 'Pending migrations: '.count($pending));
        foreach ($pending as $name) {
            $this->line("  - {$name}");
        }
        if ($multi) {
            // On a brand-new control DB the registry table does not exist until the control migration has run.
            $tenants = Schema::connection('control')->hasTable('tenants')
                ? Tenant::whereNotIn('status', ['offboarded', 'purged'])->count()
                : 0;
            $this->line("Tenant databases are migrated after the control plane ({$tenants} tenants).");
        }

        if ($dry) {
            $this->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        if ($this->option('backup') && $this->call('backup:run') !== 0) {
            return $this->abort('backup', 'Backup failed; nothing was migrated.', false);
        }

        $maintenance = (bool) $this->option('maintenance');
        if ($maintenance) {
            $this->call('down', ['--retry' => 60]);
        }

        try {
            $step = 'migrate';
            if ($multi) {
                $this->mustSucceed($this->call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]), 'control-plane migrate');
                $step = 'tenants:migrate';
                $this->mustSucceed($this->call('tenants:migrate'), 'tenant migrate (see per-tenant errors above; fix, then re-run)');
            } else {
                $this->mustSucceed($this->call('migrate', ['--force' => true]), 'migrate');
            }

            // In multi mode the catalog is synced per tenant by TenantMigrator (inside each tenant's database).
            if (! $multi) {
                $step = 'catalog';
                RoleCatalog::sync();
                $this->info('Permissions and roles are up to date.');
                SystemCatalog::sync();
                $this->info('System reference data is up to date.');
                BackfillPublicIds::dispatch();   // DATA-9: queued backfill of public ids
                BackfillMatterParties::dispatch();   // FUN-9: past opposing parties into the conflicts register
                BackfillDocumentText::dispatch();     // FUN-8: make earlier documents searchable
                BackfillCaseTypes::dispatch();        // DATA-12: matters' free-text types into the case_types list
                BackfillInvoiceFx::dispatch();       // DATA-14: issued invoices in the base currency get rate 1
            }

            if (! $this->option('skip-cache')) {
                $step = 'cache';
                foreach (['config:cache', 'route:cache', 'view:cache', 'event:cache'] as $cmd) {
                    $this->mustSucceed($this->callSilently($cmd), $cmd);
                }
                $this->info('Caches rebuilt.');
            }

            $step = 'queue:restart';
            $this->callSilently('queue:restart');

            $step = 'record';
            $changed = ReleaseLog::record($version);
        } catch (\Throwable $e) {
            return $this->abort($step, $e->getMessage(), $maintenance);
        }

        if ($maintenance) {
            $this->call('up');
        }

        $this->info($changed ? "Upgraded to {$version}." : "Already at {$version}; schema, catalog and caches verified.");

        return self::SUCCESS;
    }

    private function mustSucceed(int $code, string $what): void
    {
        if ($code !== 0) {
            throw new \RuntimeException("{$what} failed (exit {$code})");
        }
    }

    private function abort(string $step, string $message, bool $maintenance): int
    {
        if ($maintenance) {
            // Never leave the site down because an upgrade failed: expand/contract keeps the old code serving.
            $this->callSilently('up');
        }
        $this->error("Upgrade failed at step '{$step}': {$message}");
        $this->line('Rollback: redeploy the previous release (deploy/rollback.sh). Migrations are forward-only and the previous');
        $this->line('release is compatible with the schema. Restore from backup only if data was damaged. See docs/ops/UPGRADE_ROLLBACK.md.');
        $this->line('Fix the cause and re-run app:upgrade; it resumes from where it stopped.');

        return self::FAILURE;
    }

    /** @return list<string> */
    private function preflight(): array
    {
        $problems = [];
        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $problems[] = 'PHP 8.2+ required, found '.PHP_VERSION;
        }
        foreach (['pdo', 'mbstring', 'openssl', 'json', 'ctype', 'tokenizer', 'xml'] as $ext) {
            if (! extension_loaded($ext)) {
                $problems[] = "PHP extension missing: {$ext}";
            }
        }
        if (! config('app.key')) {
            $problems[] = 'APP_KEY is not set';
        }
        foreach ([storage_path(), storage_path('logs'), base_path('bootstrap/cache')] as $dir) {
            if (is_dir($dir) && ! is_writable($dir)) {
                $problems[] = "not writable: {$dir}";
            }
        }
        try {
            DB::connection(ReleaseLog::connection())->getPdo();
        } catch (\Throwable $e) {
            $problems[] = 'database unreachable: '.$e->getMessage();

            return $problems;
        }
        // CARRY-4: upgrading a legacy database turns its coded values into named states; a value nobody has mapped would
        // silently become the wrong state (an unknown invoice status becomes a draft), so it stops the upgrade instead.
        if (config('tenancy.mode') !== 'multi' && ! $this->option('accept-legacy-values') && LegacyMapping::isLegacy(DB::connection())) {
            foreach (app(LegacyMapping::class)->unmapped(DB::connection()) as $u) {
                $problems[] = "legacy value not in config/legacy_mapping.php: {$u['column']} = {$u['value']} ({$u['count']} rows); see php artisan migration:status-report";
            }
        }

        return $problems;
    }

    /** @return list<string> migration names not yet applied (control plane in multi mode, app DB otherwise) */
    private function pendingMigrations(bool $multi): array
    {
        $migrator = app('migrator');
        $path = $multi ? database_path('migrations/control') : database_path('migrations');
        $conn = $multi ? 'control' : config('database.default');
        $migrator->setConnection($conn);
        $repo = $migrator->getRepository();
        $ran = $repo->repositoryExists() ? $repo->getRan() : [];

        $files = $migrator->getMigrationFiles([$path]);

        return array_values(array_diff(array_keys($files), $ran));
    }
}
