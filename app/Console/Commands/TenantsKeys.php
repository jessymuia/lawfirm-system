<?php

namespace App\Console\Commands;

use App\Platform\PlatformAudit;
use App\Secrets\SecretInventory;
use App\Tenancy\Keys\FirmKeys;
use App\Tenancy\Keys\PlatformKeyWrapper;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Throwable;

/**
 * TEN-13: firms' data keys (docs/ops/FIRM_ENCRYPTION.md). Shows a firm's key, gives existing firms one, switches a firm
 * to its own AWS KMS key (BYOK) or back, and rewraps keys after a master-key rotation. Never prints a key.
 */
class TenantsKeys extends Command
{
    protected $signature = 'tenants:keys {slug? : one firm} {--all : every firm that is not purged}
        {--enable : give the firm a data key and move its stored secrets onto it}
        {--customer-key= : BYOK: wrap the data key with this AWS KMS key ARN (the firm\'s own key)}
        {--platform-key : stop using the firm\'s own key; wrap with the platform master key again}
        {--rewrap : after a master-key rotation, rewrap keys still under a previous master key}
        {--generate-master-key : print a new random master key for TENANCY_MASTER_KEY}';

    protected $description = "Show and manage firms' data keys (per-firm encryption, BYOK)";

    public function handle(FirmKeys $keys, TenantManager $tenants): int
    {
        if ($this->option('generate-master-key')) {
            $this->line('base64:'.base64_encode(random_bytes(32)));

            return self::SUCCESS;
        }
        if (! $tenants->isMulti()) {
            $this->error('Per-firm keys exist in multi-tenant mode only; a self-hosted install is protected by its own APP_KEY.');

            return self::FAILURE;
        }
        $slug = $this->argument('slug');
        if (! $slug && ! $this->option('all')) {
            $this->error('Name a firm, or --all.');

            return self::FAILURE;
        }
        if ($this->option('customer-key') && ! $slug) {
            $this->error('--customer-key is per firm: name the firm.');

            return self::FAILURE;
        }
        if (($this->option('enable') || $this->option('rewrap') || $this->option('customer-key') || $this->option('platform-key')) && ! PlatformKeyWrapper::configured()) {
            $this->error('Set TENANCY_MASTER_KEY first (tenants:keys --generate-master-key prints one).');

            return self::FAILURE;
        }

        $firms = Tenant::query()->when($slug, fn ($q) => $q->where('slug', $slug))->where('status', '!=', 'purged')->orderBy('slug')->get();
        if ($firms->isEmpty()) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }

        $failed = false;
        foreach ($firms as $tenant) {
            try {
                $this->line("{$tenant->slug}: ".$this->apply($keys, $tenants, $tenant));
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$tenant->slug}: ".$e->getMessage());
                PlatformAudit::record(null, 'tenant.key.failed', $tenant->slug, $this->audited(), 'failed', $e->getMessage(), 'cli');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function apply(FirmKeys $keys, TenantManager $tenants, Tenant $tenant): string
    {
        if ($this->option('enable')) {
            $had = $keys->row($tenant) !== null;
            $keys->create($tenant);
            // the firm's stored secrets move onto its key (values already there are left alone)
            $moved = $tenants->runIn($tenant, fn () => app(SecretInventory::class)->rotate('tenant', true), allowInactive: true);
            PlatformAudit::record(null, 'tenant.key.enabled', $tenant->slug, [], 'ok', "{$moved['rotated']} secret(s) re-encrypted", 'cli');

            return ($had ? 'already had a data key' : 'data key created').", {$moved['rotated']} stored secret(s) moved onto it, {$moved['broken']} unreadable.";
        }
        if ($ref = $this->option('customer-key')) {
            $done = $keys->useCustomerKey($tenant, (string) $ref);
            PlatformAudit::record(null, 'tenant.key.customer', $tenant->slug, ['key_ref' => $ref], 'ok', "data key {$done['fingerprint']}", 'cli');

            return "data key {$done['fingerprint']} is now wrapped by the firm's KMS key {$ref}.";
        }
        if ($this->option('platform-key')) {
            $done = $keys->usePlatformKey($tenant);
            PlatformAudit::record(null, 'tenant.key.platform', $tenant->slug, [], 'ok', "data key {$done['fingerprint']}", 'cli');

            return "data key {$done['fingerprint']} is wrapped by the platform master key again.";
        }
        if ($this->option('rewrap')) {
            if ($keys->row($tenant) === null) {
                return 'no data key (tenants:keys --enable).';
            }
            $changed = $keys->rewrap($tenant);
            if ($changed) {
                PlatformAudit::record(null, 'tenant.key.rewrapped', $tenant->slug, [], 'ok', null, 'cli');
            }

            return $changed ? 'rewrapped under the current master key.' : 'already under the current key (or the firm\'s own KMS key).';
        }

        $s = $keys->status($tenant);
        if ($s['key'] === 'none') {
            return 'no data key: its secrets are under the app key and its backups are not encrypted (tenants:keys --enable).';
        }

        return sprintf('%s, %s%s, fingerprint %s%s', $s['key'], $s['provider'], $s['key_ref'] ? " ({$s['key_ref']})" : '', $s['fingerprint'],
            isset($s['usable']) ? ($s['usable'] ? ', usable' : ', NOT USABLE: '.$s['problem']) : '');
    }

    /** @return array<string, mixed> */
    private function audited(): array
    {
        return array_filter(['enable' => $this->option('enable'), 'customer_key' => $this->option('customer-key'),
            'platform_key' => $this->option('platform-key'), 'rewrap' => $this->option('rewrap')]);
    }
}
