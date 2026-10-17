<?php

namespace App\Console\Commands;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use App\Webhooks\WebhookService;
use Illuminate\Console\Command;

/** PLAT-11: send due webhook deliveries (first attempts and retries) and prune old ones. Scheduled every minute. */
class WebhooksDeliver extends Command
{
    protected $signature = 'webhooks:deliver {--tenant= : Only this tenant slug}';

    protected $description = 'Send due webhook deliveries (with retry/backoff) for every firm and prune old delivery logs';

    public function handle(WebhookService $svc, TenantManager $tenants): int
    {
        $each = function (string $label) use ($svc) {
            $r = $svc->runDue();
            $r['sent'] && $this->line(sprintf('%s: sent=%d delivered=%d retry=%d dead=%d', $label, $r['sent'], $r['delivered'], $r['retry'], $r['dead']));
            $svc->prune();
        };
        if (! $tenants->isMulti()) {
            $each('this firm');

            return self::SUCCESS;
        }
        $q = Tenant::where('status', 'active')->orderBy('slug');
        $this->option('tenant') && $q->where('slug', $this->option('tenant'));
        $failed = 0;
        foreach ($q->get() as $t) {
            try {
                $tenants->runIn($t, fn () => $each($t->slug));
            } catch (\Throwable $e) {
                $failed++;
                $this->error("{$t->slug}: {$e->getMessage()}");   // one broken firm must not stop the others
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
