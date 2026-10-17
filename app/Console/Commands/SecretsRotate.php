<?php

namespace App\Console\Commands;

use App\Secrets\SecretInventory;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** SEC-15: re-encrypt every stored secret under the current APP_KEY (dry run unless --execute). */
class SecretsRotate extends Command
{
    protected $signature = 'secrets:rotate {--execute : Actually re-encrypt (default reports only)} {--tenant= : Only this tenant slug}';

    protected $description = 'Re-encrypt stored secrets under the current APP_KEY after a key change (old key(s) must be in APP_PREVIOUS_KEYS)';

    public function handle(SecretInventory $inv, TenantManager $tenants): int
    {
        $ex = (bool) $this->option('execute');
        $bad = 0;
        $report = function (string $label, array $r) use (&$bad, $ex) {
            $this->line(sprintf('%-22s %s=%d already-current=%d unreadable=%d', $label, $ex ? 'rotated' : 'would-rotate', $r['rotated'], $r['already_current'], $r['broken']));
            $bad += $r['broken'];
        };
        if (! $this->option('tenant')) {
            $report('control plane', $inv->rotate('control', $ex));
        }
        if (! $tenants->isMulti()) {
            $report('this firm', $inv->rotate('tenant', $ex));
        } else {
            $q = Tenant::where('status', 'active')->orderBy('slug');
            $this->option('tenant') && $q->where('slug', $this->option('tenant'));
            foreach ($q->get() as $t) {
                try {
                    $tenants->runIn($t, fn () => $report($t->slug, $inv->rotate('tenant', $ex)));
                } catch (\Throwable $e) {
                    $bad++;
                    $this->error("{$t->slug}: {$e->getMessage()}");
                }
            }
        }
        $ex || $this->comment('Dry run. Re-run with --execute, then `secrets:check` before removing APP_PREVIOUS_KEYS.');

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
