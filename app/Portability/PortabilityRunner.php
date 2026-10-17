<?php

namespace App\Portability;

use App\Models\User;
use App\Tenancy\IdentityService;
use App\Tenancy\Models\Identity;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Str;

/** PLAT-9: runs an export/import against the single install, or inside one tenant (multi mode). */
class PortabilityRunner
{
    public function __construct(private TenantManager $tenants) {}

    /** Run $fn against the active DB for a single install, or inside tenant $slug in multi mode. */
    public function within(?string $slug, \Closure $fn): mixed
    {
        if ($slug === null) {
            if ($this->tenants->isMulti()) {
                throw new PortabilityException('This is a multi-tenant deployment: say which firm with --tenant=<slug>.');
            }

            return $fn();
        }
        if (! $this->tenants->isMulti()) {
            throw new PortabilityException('--tenant is only meaningful in a multi-tenant deployment.');
        }
        $tenant = Tenant::where('slug', $slug)->first() ?? throw new PortabilityException("No such tenant: {$slug}");

        // an import may target a tenant still provisioning/suspended; an export of an offboarded firm is allowed too
        return $this->tenants->runIn($tenant, $fn, allowInactive: true);
    }

    /**
     * Import a bundle. In multi mode the people in it are then linked to control-plane identities (created from their
     * existing password hash when they have none), so they can sign in to the firm exactly as before.
     *
     * @return array{tables:array<string,int>,files:int,dry_run:bool,notes:list<string>}
     */
    public function import(?string $slug, Bundle $bundle, array $opts): array
    {
        $r = $this->within($slug, fn () => (new Importer)->import($bundle, $opts));
        if ($slug !== null && ! $r['dry_run']) {
            $n = $this->linkIdentities($slug);
            $r['notes'][] = "{$n} user(s) linked to sign-in identities.";
        }

        return $r;
    }

    /** Idempotent. @return int users linked */
    public function linkIdentities(string $slug): int
    {
        return (int) $this->within($slug, function () use ($slug) {
            $tenant = Tenant::where('slug', $slug)->firstOrFail();
            $svc = app(IdentityService::class);
            $n = 0;
            foreach (User::query()->whereNull('platform_staff_id')->get() as $user) {
                if (! $user->email || ! $user->password) {
                    continue;
                }
                $identity = $svc->findByEmail($user->email)
                    ?? Identity::create(['email' => Str::lower(trim($user->email)), 'password' => $user->password]);
                $svc->link($identity, $tenant, $user);
                $n++;
            }

            return $n;
        });
    }

    /** Export to a zip file. @return array{path:string,sha256:string,manifest:array} */
    public function exportZip(?string $slug, string $zipPath, bool $withFiles = true): array
    {
        return $this->zip($zipPath, fn (string $tmp) => $this->within($slug, fn () => (new Exporter)->export($tmp, $withFiles)));
    }

    /** TEN-9: export one firm by its control-plane record, whatever the deployment mode (the platform manages firms in both). */
    public function exportTenantZip(Tenant $tenant, string $zipPath): array
    {
        return $this->zip($zipPath, fn (string $tmp) => $this->tenants->runIn($tenant, fn () => (new Exporter)->export($tmp, true), allowInactive: true));
    }

    /** @return array{path:string,sha256:string,manifest:array} */
    private function zip(string $zipPath, \Closure $export): array
    {
        $tmp = sys_get_temp_dir().'/export-'.bin2hex(random_bytes(6));
        try {
            $manifest = $export($tmp);
            @mkdir(dirname($zipPath), 0700, true);
            Bundle::zip($tmp, $zipPath);
        } finally {
            is_dir($tmp) && Bundle::rmrf($tmp);
        }

        return ['path' => $zipPath, 'sha256' => hash_file('sha256', $zipPath), 'manifest' => $manifest];
    }
}
