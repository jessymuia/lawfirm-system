<?php

namespace App\Tenancy;

use App\Catalog\SystemCatalog;
use App\Models\User;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use Database\Seeders\ExpenseCategorySeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * TEN-9: provision, suspend, resume, offboard and purge tenants.
 *
 * Provisioning is resumable: re-running it for a tenant still in
 * `provisioning` picks up where it stopped (each step is idempotent).
 */
class TenantLifecycle
{
    public function __construct(
        private TenantManager $tenants,
        private TenantMigrator $migrator,
    ) {
    }

    /**
     * @param  array{slug:string,name:string,admin_email:string,admin_name?:string,admin_phone?:string,admin_password?:string,admin_password_hash?:string,domain?:string,database?:string,region?:string,plan?:string,cell?:?string}  $spec
     * @return array{tenant: Tenant, admin_password: ?string} admin_password is returned once, only if generated
     */
    public function provision(array $spec): array
    {
        $slug = $spec['slug'];
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/', $slug)) {
            throw new InvalidArgumentException('Invalid tenant slug.');
        }

        $tenant = Tenant::where('slug', $slug)->first();
        if ($tenant && $tenant->status !== 'provisioning') {
            throw new RuntimeException("Tenant [{$slug}] already exists (status: {$tenant->status}).");
        }

        if (! $tenant) {
            // TEN-4 / TEN-15: an explicit cell, or the least-loaded accepting cell (in the firm's region, if it has one).
            $cell = $spec['cell'] ?? app(CellRegistry::class)->place($spec['region'] ?? null);
            $cellRow = app(CellRegistry::class)->find($cell);
            $tenant = Tenant::create([
                // TEN-2: where its files and background work live, from its cell or the platform default
                'files_disk' => $cellRow?->files_disk ?: FirmStorage::defaultDisk(),
                'queue' => $cellRow?->queue ?: FirmStorage::defaultQueue(),
                'slug' => $slug,
                'name' => $spec['name'],
                'status' => 'provisioning',
                'region' => $spec['region'] ?? null,
                'plan' => $spec['plan'] ?? null,
                'cell' => $cell,
                'database' => $spec['database'] ?? $this->defaultDatabaseName($slug, $cell),
            ]);
        }

        $this->createDatabase($tenant);

        if (! $this->migrator->migrate($tenant)) {
            throw new RuntimeException("Provisioning [{$slug}] failed at migration: ".$tenant->fresh()->migration_error);
        }

        // TEN-13: its own data key, before anything is encrypted for it
        if (app(Keys\FirmKeys::class)->enabled()) {
            app(Keys\FirmKeys::class)->create($tenant);
        }

        // One person = one identity: the same email in a second firm reuses
        // their existing login instead of creating another.
        [$identity, $generated] = app(IdentityService::class)
            ->registerOrReuse($spec['admin_email'], $spec['admin_password'] ?? null, $spec['admin_password_hash'] ?? null);

        $this->tenants->runIn($tenant, function () use ($spec, $identity, $tenant) {
            $this->seedCatalog();
            $this->createAdmin($tenant, $identity, $spec['admin_name'] ?? 'Administrator', $spec['admin_phone'] ?? '');
        }, allowInactive: true);

        $domain = $spec['domain'] ?? ($this->baseDomain() ? "{$slug}.".$this->baseDomain() : null);
        if ($domain) {
            $tenant->domains()->firstOrCreate(['domain' => strtolower($domain)]);
        }

        $tenant->update(['status' => 'active']);

        return ['tenant' => $tenant->fresh(), 'admin_password' => $generated];
    }

    public function suspend(Tenant $tenant): void
    {
        $this->requireStatus($tenant, ['active'], 'suspend');
        $tenant->update(['status' => 'suspended']);
    }

    public function resume(Tenant $tenant): void
    {
        $this->requireStatus($tenant, ['suspended'], 'resume');
        $tenant->update(['status' => 'active']);
    }

    /** Stop service now and schedule permanent deletion after the retention window. */
    public function offboard(Tenant $tenant, ?int $retentionDays = null): void
    {
        $this->requireStatus($tenant, ['active', 'suspended'], 'offboard');

        $tenant->update([
            'status' => 'offboarded',
            'offboarded_at' => now(),
            'purge_after' => now()->addDays($retentionDays ?? config('tenancy.retention_days')),
        ]);

        // TEN-9: deletion starts with an export of everything, kept through the retention window
        \App\Jobs\ExportOffboardedTenant::dispatch($tenant->slug);
    }

    public function setLegalHold(Tenant $tenant, bool $hold): void
    {
        $tenant->update(['legal_hold' => $hold]);
    }

    /**
     * TEN-9: permanently delete an offboarded firm, as one recorded sequence: its final export exists and verifies →
     * (retention window has passed) → database dropped → files under its prefix deleted on every disk → further purge
     * steps (encryption key, warehouse; see config tenancy.purge_steps) → sign-in links removed → final export deleted.
     * The outcome of every step is kept on the tenant row (`purge_report`); nothing in it is firm data.
     *
     * Refused under legal hold (even when the firm itself asked to be deleted), before the retention window has passed,
     * and without a verified final export unless $withoutExport says why there is none.
     *
     * @return array<string, mixed> the purge report
     */
    public function purge(Tenant $tenant, ?string $withoutExport = null): array
    {
        if ($tenant->legal_hold) {
            throw new RuntimeException("Tenant [{$tenant->slug}] is under legal hold; deletion refused.");
        }
        if ($tenant->status !== 'offboarded') {
            throw new RuntimeException("Tenant [{$tenant->slug}] must be offboarded before it can be purged.");
        }
        if ($tenant->purge_after === null || $tenant->purge_after->isFuture()) {
            throw new RuntimeException("Tenant [{$tenant->slug}] is inside its retention window until {$tenant->purge_after}.");
        }

        $report = ['started_at' => now()->toIso8601String()];
        if ($withoutExport !== null) {
            if (trim($withoutExport) === '') {
                throw new InvalidArgumentException('Purging without a final export needs a reason.');
            }
            $report['final_export'] = 'skipped: '.$withoutExport;
        } else {
            $report['final_export'] = $this->verifiedFinalExport($tenant);
        }

        $this->dropDatabase($tenant);
        $report['database'] = 'dropped';

        // every disk firms' files may live on, and certainly the one this firm's row names (TEN-2)
        foreach (array_unique(array_filter([...(array) config('tenancy.storage_disks'), $tenant->files_disk])) as $disk) {
            $storage = Storage::disk($disk);
            $storage->deleteDirectory('tenants/'.$tenant->slug);
            if ($storage->allFiles('tenants/'.$tenant->slug) !== []) {
                throw new RuntimeException("Files of [{$tenant->slug}] are still on disk {$disk} after deletion; purge stopped (database already dropped).");
            }
            $report['files'][$disk] = 'deleted';
        }

        $steps = [];
        foreach ((array) config('tenancy.purge_steps') as $class) {
            $step = app($class);
            if (! $step instanceof PurgeStep) {
                throw new RuntimeException("{$class} is not a ".PurgeStep::class.'.');
            }
            $steps[$step->name()] = $step->purge($tenant);
        }
        $report['encryption_key'] = $steps['encryption_key'] ?? 'none: the encryption_key purge step is not in tenancy.purge_steps';
        $report['warehouse'] = $steps['warehouse'] ?? 'none: no analytics warehouse holds firm rows (ENG-26 not enabled)';
        $report += array_diff_key($steps, ['encryption_key' => 1, 'warehouse' => 1]);

        // who could sign in to this firm; an identity with no other firm left is personal data with no purpose
        $identityIds = DB::connection(config('tenancy.control_connection'))->table('tenant_memberships')->where('tenant_id', $tenant->id)->pluck('identity_id');
        DB::connection(config('tenancy.control_connection'))->table('tenant_memberships')->where('tenant_id', $tenant->id)->delete();
        $orphans = Identity::whereIn('id', $identityIds)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tenant_memberships')->whereColumn('tenant_memberships.identity_id', 'identities.id'))->pluck('id');
        Identity::whereIn('id', $orphans)->delete();
        $report['sign_in'] = count($identityIds).' membership(s) removed, '.count($orphans).' identity(ies) with no other firm deleted';

        $tenant->domains()->delete();

        if ($tenant->final_export_path) {
            @unlink($tenant->final_export_path);
            $report['final_export_file'] = file_exists($tenant->final_export_path) ? 'could not be deleted' : 'deleted';
        }

        $report['finished_at'] = now()->toIso8601String();
        $tenant->update(['status' => 'purged', 'purged_at' => now(), 'purge_report' => $report, 'final_export_path' => null]);

        return $report;
    }

    /** The final export must exist and still be the file that was made. */
    private function verifiedFinalExport(Tenant $tenant): string
    {
        $path = $tenant->final_export_path;
        if (! $tenant->final_export_at || ! $path) {
            $why = $tenant->final_export_error ? ' (it failed: '.$tenant->final_export_error.')' : '';
            throw new RuntimeException("Tenant [{$tenant->slug}] has no final export{$why}; run `tenants:final-export {$tenant->slug}` or purge with a reason for skipping it.");
        }
        if (! is_file($path) || ! hash_equals((string) $tenant->final_export_sha256, hash_file('sha256', $path))) {
            throw new RuntimeException("The final export of [{$tenant->slug}] is missing or damaged; make a new one before purging.");
        }

        return 'made '.$tenant->final_export_at->toIso8601String().', sha256 '.$tenant->final_export_sha256;
    }

    // ---- internals ------------------------------------------------------

    private function requireStatus(Tenant $tenant, array $allowed, string $action): void
    {
        if (! in_array($tenant->status, $allowed, true)) {
            throw new RuntimeException("Cannot {$action} tenant [{$tenant->slug}] in status [{$tenant->status}].");
        }
    }

    private function baseDomain(): ?string
    {
        return config('tenancy.base_domain') ?: null;
    }

    private function templateDriver(): string
    {
        return config('database.connections.'.config('tenancy.tenant_connection_template').'.driver');
    }

    public function defaultDatabaseName(string $slug, string $cell = 'default'): string
    {
        return $this->templateDriver() === 'sqlite'
            ? app(CellRegistry::class)->sqliteDirectory($cell).'/'.$slug.'.sqlite'
            : 'tenant_'.str_replace('-', '_', $slug);
    }

    public function createDatabase(Tenant $tenant): void
    {
        if ($this->templateDriver() === 'sqlite') {
            File::ensureDirectoryExists(dirname($tenant->database));
            if (! file_exists($tenant->database)) {
                touch($tenant->database);
            }

            return;
        }

        DB::connection($this->adminConnection($tenant->cell))->statement($this->createDatabaseSql($tenant->database));
    }

    public function dropDatabase(Tenant $tenant): void
    {
        if ($this->templateDriver() === 'sqlite') {
            @unlink($tenant->database);

            return;
        }

        $this->assertSafeName($tenant->database);
        DB::connection($this->adminConnection($tenant->cell))->statement("DROP DATABASE IF EXISTS `{$tenant->database}`");
    }

    public function createDatabaseSql(string $name): string
    {
        $this->assertSafeName($name);

        return "CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
    }

    private function assertSafeName(string $name): void
    {
        if (! preg_match('/^[A-Za-z0-9_]{1,64}$/', $name)) {
            throw new InvalidArgumentException('Unsafe database name.');
        }
    }

    /** Server-level connection (no database selected) to a cell, for CREATE/DROP DATABASE. */
    private function adminConnection(?string $cell = null): string
    {
        config(['database.connections.tenant_admin' => app(CellRegistry::class)->serverConfig($cell ?: 'default') + ['database' => null]]);
        DB::purge('tenant_admin');

        return 'tenant_admin';
    }

    /** Roles, permissions and reference data every firm starts with. */
    private function seedCatalog(): void
    {
        // Every seeder here is idempotent (firstOrCreate), so a resumed
        // provisioning run never duplicates rows. (An emptiness check is not
        // enough: a migration already inserts an 'Unclassified' court type.)
        (new RoleSeeder)->run();
        SystemCatalog::sync();                 // TEN-11: platform-owned, re-synced on every upgrade
        (new ExpenseCategorySeeder)->run();    // the firm's own starter categories, never re-synced
    }

    private function createAdmin(Tenant $tenant, Identity $identity, string $name, string $phone): void
    {
        $user = User::withTrashed()->where('email', $identity->email)->first();

        if (! $user) {
            $user = User::create([
                'name' => $name,
                'email' => $identity->email,
                'password' => $identity->password,
                // NOT NULL columns on users; the admin can edit them after first login.
                'phone_number' => $phone,
                'department' => 'Administration',
                'hire_date' => now()->toDateString(),
            ]);
        }

        app(IdentityService::class)->link($identity, $tenant, $user);
        $user->assignRole('admin');
    }
}
