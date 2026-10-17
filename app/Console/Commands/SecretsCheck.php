<?php

namespace App\Console\Commands;

use App\Secrets\SecretInventory;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** SEC-15: which key can read each stored secret? Fails if any value is unreadable or (with --strict) still needs a previous key. */
class SecretsCheck extends Command
{
    protected $signature = 'secrets:check {--strict : Also fail while any value still needs APP_PREVIOUS_KEYS} {--tenant= : Only this tenant slug}';

    protected $description = 'Report whether every stored secret decrypts with the current APP_KEY (safe to drop APP_PREVIOUS_KEYS when all are current)';

    public function handle(SecretInventory $inv, TenantManager $tenants): int
    {
        $bad = 0;
        $report = function (string $label, array $r) use (&$bad) {
            $this->line(sprintf('%-22s current=%d needs-previous-key=%d unreadable=%d', $label, $r['current'], $r['previous'], $r['broken']));
            $bad += $r['broken'] + ($this->option('strict') ? $r['previous'] : 0);
        };
        $report('control plane', $inv->check('control'));
        if (! $tenants->isMulti()) {
            $report('this firm', $inv->check('tenant'));
        } else {
            $q = Tenant::where('status', 'active')->orderBy('slug');
            $this->option('tenant') && $q->where('slug', $this->option('tenant'));
            foreach ($q->get() as $t) {
                try {
                    $tenants->runIn($t, fn () => $report($t->slug, $inv->check('tenant')));
                } catch (\Throwable $e) {
                    $bad++;
                    $this->error("{$t->slug}: {$e->getMessage()}");
                }
            }
        }

        return $bad ? self::FAILURE : self::SUCCESS;
    }
}
