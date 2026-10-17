<?php

namespace App\Console\Commands;

use App\Billing\Billing;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;

/** PLAT-4: invoice every subscription whose period ended, then run dunning. Idempotent; safe to run more often than daily. */
class BillingRun extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Invoice due subscription periods (with metered overage) and send dunning notices';

    public function handle(Billing $billing, TenantManager $tenants): int
    {
        if (! $tenants->isMulti()) {
            $this->line('Billing applies to the multi-tenant (SaaS) deployment only.');

            return self::SUCCESS;
        }

        $this->info('Invoices created: '.$billing->runDue());
        $a = app(\App\Billing\Autopay::class)->chargeDue();   // PLAT-4: saved cards, before any reminder goes out
        $this->info("Automatic payments: {$a['charged']} charged, {$a['failed']} declined, {$a['skipped']} waiting to retry.");
        $d = $billing->dun();
        $this->info("Dunning: {$d['reminders']} reminder(s), {$d['past_due']} newly past due, {$d['suspended']} suspended.");
        $lapsed = $billing->offboardLapsedTrials();
        $this->info('Lapsed trials offboarded: '.count($lapsed).($lapsed ? ' ('.implode(', ', $lapsed).')' : ''));

        return self::SUCCESS;
    }
}
