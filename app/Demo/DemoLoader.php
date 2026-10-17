<?php

namespace App\Demo;

use App\Billing\Billing;
use App\Billing\PlanStore;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Closure;
use Database\Seeders\Kenya\KenyaDemoData;
use Database\Seeders\Kenya\KenyaFirmSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Loads and removes the demo data of Law Management System. A demo "pack" is one complete firm (its own database, people with
 * real sign-ins, clients, matters, hearings, tasks, time, invoices and a subscription). The first pack is the DEFAULT: it is
 * what a fresh install gets. The others are loaded on demand (`php artisan law:demo-load`) and can be taken away again
 * (`php artisan law:demo-remove`), which only ever touches the packs listed in KenyaDemoData, never a real firm.
 */
class DemoLoader
{
    /** @param Closure(string $level, string $message): void|null $say  receives progress lines (info | line | warn | error) */
    public function __construct(
        private TenantManager $tenants,
        private TenantLifecycle $lifecycle,
        private Billing $billing,
        private ?Closure $say = null,
    ) {}

    public function reporting(Closure $say): static
    {
        $this->say = $say;

        return $this;
    }

    /** @return array<string, array<string, mixed>> slug => firm */
    public function packs(): array
    {
        $out = [];
        foreach (KenyaDemoData::firms() as $i => $firm) {
            $firm['default'] = $i === 0;
            $out[$firm['slug']] = $firm;
        }

        return $out;
    }

    public function defaultSlug(): string
    {
        return (string) array_key_first($this->packs());
    }

    /** @param list<string> $slugs  @return list<string> the packs, validated; an empty list means "the default one" */
    public function resolve(array $slugs, bool $all = false): array
    {
        $packs = $this->packs();
        if ($all) {
            return array_keys($packs);
        }
        if ($slugs === []) {
            return [$this->defaultSlug()];
        }
        foreach ($slugs as $s) {
            if (! isset($packs[$s])) {
                throw new RuntimeException("Unknown demo pack [{$s}]. Available: ".implode(', ', array_keys($packs)).'.');
            }
        }

        return array_values(array_unique($slugs));
    }

    public function guard(): void
    {
        $this->tenants->requireMulti(TenantManager::MULTI_HINT);
        if (app()->isProduction()) {
            throw new RuntimeException('Demo data is for local and test installs only; this is a production environment.');
        }
    }

    public function status(string $slug): string
    {
        $t = Tenant::where('slug', $slug)->first();

        return $t && ! in_array($t->status, ['provisioning', 'purged'], true) ? 'loaded' : 'not loaded';
    }

    // ---- control plane + operator ------------------------------------------------------------------------------------

    public function prepareControlPlane(): void
    {
        $c = (array) config('database.connections.'.config('tenancy.control_connection'));
        $name = (string) ($c['database'] ?? '');
        if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('CONTROL_DB_DATABASE may only contain letters, digits and underscores.');
        }
        $pdo = new \PDO(sprintf('mysql:host=%s;port=%s', $c['host'], $c['port']), (string) $c['username'], (string) $c['password']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        \Artisan::call('migrate', ['--database' => config('tenancy.control_connection'), '--path' => 'database/migrations/control', '--force' => true]);
        PlanStore::seedIfEmpty();
    }

    /** @return array{name: string, email: string, password: string, role: string} */
    public function superAdmin(bool $resetPassword = false): array
    {
        $a = KenyaDemoData::superAdmin();
        $staff = PlatformStaff::where('email', mb_strtolower($a['email']))->first();
        if (! $staff) {
            PlatformStaff::create(['name' => $a['name'], 'email' => mb_strtolower($a['email']), 'password' => Hash::make($a['password']), 'role' => $a['role']]);
            PlatformAudit::record(null, 'staff.create', null, ['email' => $a['email'], 'role' => $a['role']], 'ok', 'via demo loader', 'cli');
            $this->say('info', "Super admin created: {$a['email']}");
        } elseif ($resetPassword) {
            $staff->update(['password' => Hash::make($a['password']), 'role' => $a['role'], 'active' => true, 'failed_logins' => 0, 'locked_until' => null]);
            $this->say('info', "Super admin reset: {$a['email']}");
        } else {
            $this->say('line', "Super admin exists: {$a['email']} (use --reset-admin to restore the demo password)");
        }

        return $a;
    }

    public function removeSuperAdmin(): bool
    {
        $a = KenyaDemoData::superAdmin();

        return (bool) PlatformStaff::where('email', mb_strtolower($a['email']))->delete();
    }

    // ---- loading ----------------------------------------------------------------------------------------------------

    /** Provision (if needed) and fill one pack. Safe to repeat: what is already there is left alone. */
    public function load(string $slug): void
    {
        $firm = $this->packs()[$slug] ?? throw new RuntimeException("Unknown demo pack [{$slug}].");
        $tenant = Tenant::where('slug', $firm['slug'])->first();
        $adminUser = collect($firm['users'])->firstWhere('role', 'admin');

        if (! $tenant || in_array($tenant->status, ['provisioning', 'purged'], true)) {
            $this->say('line', "Creating {$firm['name']} …");
            $this->lifecycle->provision([
                'slug' => $firm['slug'], 'name' => $firm['name'], 'domain' => $firm['domain'], 'plan' => $firm['plan'],
                'admin_email' => $adminUser['email'], 'admin_name' => $adminUser['name'], 'admin_phone' => $adminUser['phone'],
                'admin_password' => $adminUser['password'],
            ]);
            $tenant = Tenant::where('slug', $firm['slug'])->firstOrFail();
        }

        foreach (array_merge([$firm['domain']], $firm['extra_domains']) as $domain) {
            $tenant->domains()->firstOrCreate(['domain' => strtolower($domain)]);
        }

        $this->tenants->runIn($tenant, fn () => (new KenyaFirmSeeder($firm))->run());
        $this->subscription($tenant, $firm, $adminUser['email']);
        $this->say('info', "{$firm['name']}: ready.");
    }

    /** @param array<string, mixed> $firm */
    private function subscription(Tenant $tenant, array $firm, string $email): void
    {
        if ($this->billing->subscription($tenant)) {
            return;
        }
        $plan = $firm['subscription']['plan'];
        try {
            if ($plan === 'trial') {
                $this->billing->startTrial($tenant, $email);

                return;
            }
            $this->billing->subscribe($tenant, $plan, 'KES', 'manual', $email);
            if ($firm['subscription']['paid']) {
                $db = DB::connection(config('tenancy.control_connection'));
                foreach ($db->table('billing_invoices')->where('tenant_id', $tenant->id)->where('status', 'open')->get() as $inv) {
                    $this->billing->recordPayment($inv->number, (int) $inv->total, $inv->currency, 'manual', 'DEMO-'.$inv->number);
                }
            }
        } catch (Throwable $e) {
            $this->say('warn', "{$firm['name']}: subscription skipped: ".$e->getMessage());
        }
    }

    // ---- removing ---------------------------------------------------------------------------------------------------

    /**
     * Take one demo firm away completely: its database, files, sign-ins, billing records and its row, so the same pack
     * can be loaded fresh later. Refuses anything that is not a demo pack, and a firm under legal hold.
     *
     * @return bool false when there was nothing to remove
     */
    public function remove(string $slug): bool
    {
        $firm = $this->packs()[$slug] ?? throw new RuntimeException("[{$slug}] is not a demo pack; refusing to remove it.");
        $tenant = Tenant::where('slug', $firm['slug'])->first();
        if (! $tenant) {
            return false;
        }
        if ($tenant->legal_hold) {
            throw new RuntimeException("{$firm['name']} is under legal hold; remove the hold first.");
        }

        $control = DB::connection(config('tenancy.control_connection'));

        try {
            $this->lifecycle->dropDatabase($tenant);
        } catch (Throwable $e) {
            $this->say('warn', 'Database: '.$e->getMessage());
        }
        foreach (array_unique(array_filter([...(array) config('tenancy.storage_disks'), $tenant->files_disk])) as $disk) {
            try {
                Storage::disk($disk)->deleteDirectory('tenants/'.$tenant->slug);
            } catch (Throwable) {
            }
        }
        foreach ((array) config('tenancy.purge_steps') as $class) {
            try {
                app($class)->purge($tenant);
            } catch (Throwable) {
            }
        }

        $identityIds = $control->table('tenant_memberships')->where('tenant_id', $tenant->id)->pluck('identity_id');
        $control->statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $control->table('tenant_memberships')->where('tenant_id', $tenant->id)->delete();
            Identity::whereIn('id', $identityIds)->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tenant_memberships')->whereColumn('tenant_memberships.identity_id', 'identities.id'))->delete();
            $schema = Schema::connection(config('tenancy.control_connection'));
            foreach ($schema->getTableListing() as $table) {
                $table = preg_replace('/^.*\./', '', $table);
                if ($table !== 'tenants' && $schema->hasColumn($table, 'tenant_id')) {
                    $control->table($table)->where('tenant_id', $tenant->id)->delete();
                }
            }
            $tenant->domains()->delete();
            $tenant->delete();
        } finally {
            $control->statement('SET FOREIGN_KEY_CHECKS=1');
        }
        PlatformAudit::record(null, 'demo.remove', $slug, [], 'ok', 'demo pack removed', 'cli');
        $this->say('info', "{$firm['name']}: removed.");

        return true;
    }

    private function say(string $level, string $message): void
    {
        if ($this->say) {
            ($this->say)($level, $message);
        }
    }
}
