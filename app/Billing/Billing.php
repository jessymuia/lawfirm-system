<?php

namespace App\Billing;

use App\Entitlements\PlanCatalog;
use App\Mail\DunningNotice;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * PLAT-4: subscriptions, invoices, payments, dunning. Control plane only. Billing is in ADVANCE: each period's base price is
 * invoiced when the period starts, together with the previous period's metered overage (peak users and storage above the plan's
 * limits: the ENG-24 gauges). Plan changes are prorated by exact seconds remaining. Everything is integer minor units, every
 * generation step is idempotent, and a webhook replay can never pay an invoice twice.
 */
class Billing
{
    public function __construct(private PlanCatalog $catalog)
    {
        PlanStore::sync();   // the operator-managed plans, over the config defaults
    }

    private function db()
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function price(string $plan, string $currency): int
    {
        $p = config("billing.plans.{$plan}.{$currency}");
        if (! in_array($currency, (array) config('billing.currencies'), true) || $p === null) {
            throw new BillingException("Plan [{$plan}] cannot be billed in [{$currency}].");
        }

        return (int) $p;
    }

    public function subscription(Tenant $t): ?object
    {
        return $this->db()->table('subscriptions')->where('tenant_id', $t->id)->first();
    }

    // ---- lifecycle -------------------------------------------------------------------------------------------------

    public function subscribe(Tenant $t, string $plan, string $currency, string $provider = 'manual', ?string $email = null, int $trialDays = 0, ?CarbonImmutable $at = null): object
    {
        $at ??= $this->now();
        if ($this->subscription($t)) {
            throw new BillingException('That firm already has a subscription.');
        }
        if (! in_array($provider, ['manual', 'stripe', 'mpesa'], true)) {
            throw new BillingException('Unknown provider.');
        }
        $this->price($plan, $currency);   // validates plan + currency

        $id = $this->db()->transaction(function () use ($t, $plan, $currency, $provider, $email, $trialDays, $at) {
            $trial = $trialDays > 0;
            $end = $trial ? $at->addDays($trialDays) : $at->addMonthNoOverflow();
            $id = $this->db()->table('subscriptions')->insertGetId([
                'tenant_id' => $t->id, 'plan' => $plan, 'currency' => $currency, 'provider' => $provider, 'status' => $trial ? 'trialing' : 'active',
                'billing_email' => $email, 'period_start' => $at, 'period_end' => $end, 'trial_ends_at' => $trial ? $end : null,
                'created_at' => $at, 'updated_at' => $at,
            ]);
            $t->update(['plan' => $plan]);
            if (! $trial) {
                $sub = $this->db()->table('subscriptions')->where('id', $id)->first();
                $this->invoice($sub, 'period', $at, $end, [['base', "Subscription: {$plan}", 1, $this->price($plan, $currency)]], $at);
            }

            return $id;
        });

        return $this->db()->table('subscriptions')->where('id', $id)->first() ?? throw new BillingException('The subscription was not recorded.');
    }

    /** PLAT-13: a self-service firm starts on the free `trial` plan for billing.trial.days. */
    public function startTrial(Tenant $t, ?string $email = null, ?string $currency = null, ?CarbonImmutable $at = null): object
    {
        $at ??= $this->now();
        $days = max(1, (int) config('billing.trial.days', 14));
        $sub = $this->subscribe($t, 'trial', $currency ?: (string) config('billing.trial.currency', 'KES'), 'manual', $email, $days, $at);
        $t->update(['trial_ends_at' => $at->addDays($days)]);

        return $sub;
    }

    /**
     * PLAT-13: firms whose trial lapsed more than billing.trial.offboard_after_days ago, and that never chose a plan, are
     * offboarded: the normal retention window, final export and purge follow (TEN-9). A legal hold still blocks the purge.
     *
     * @return list<string> slugs offboarded
     */
    public function offboardLapsedTrials(?CarbonImmutable $now = null): array
    {
        $after = (int) config('billing.trial.offboard_after_days', 60);
        if ($after <= 0) {
            return [];
        }
        $now ??= $this->now();
        $done = [];
        $ids = $this->db()->table('subscriptions')->where('status', 'expired')->whereNull('converted_at')
            ->where('expired_at', '<=', $now->subDays($after))->pluck('tenant_id');
        foreach (Tenant::whereIn('id', $ids)->whereIn('status', ['active', 'suspended'])->get() as $tenant) {
            app(TenantLifecycle::class)->offboard($tenant);
            $done[] = $tenant->slug;
        }

        return $done;
    }

    public function cancel(Tenant $t): object
    {
        $sub = $this->subscription($t) ?? throw new BillingException('No subscription.');
        $this->db()->table('subscriptions')->where('id', $sub->id)->update(['cancel_at_period_end' => true, 'updated_at' => now()]);

        return $this->subscription($t);
    }

    /** Upgrade or downgrade now. Unused time on the old plan is credited, the rest of the period is charged on the new one. */
    public function changePlan(Tenant $t, string $newPlan, ?CarbonImmutable $at = null): array
    {
        $at ??= $this->now();
        $sub = $this->subscription($t) ?? throw new BillingException('No subscription.');
        if ($sub->status === 'canceled') {
            throw new BillingException('The subscription is canceled.');
        }
        if ($newPlan === $sub->plan) {
            throw new BillingException('Already on that plan.');
        }
        $new = $this->price($newPlan, $sub->currency);
        $old = $this->price($sub->plan, $sub->currency);

        return $this->db()->transaction(function () use ($t, $sub, $newPlan, $new, $old, $at) {
            $result = ['invoice' => null, 'credit_added' => 0];
            $start = CarbonImmutable::parse($sub->period_start);
            $end = CarbonImmutable::parse($sub->period_end);

            if ($sub->status !== 'trialing' && $at->lt($end)) {
                $total = $end->getTimestamp() - $start->getTimestamp();
                $rem = max(0, $end->getTimestamp() - max($at->getTimestamp(), $start->getTimestamp()));
                $credit = Money::ratio($old, $rem, $total);
                $charge = Money::ratio($new, $rem, $total);
                $net = $charge - $credit;
                $lines = [['proration_credit', "Unused time on {$sub->plan}", 1, -$credit], ['proration_charge', "Remaining time on {$newPlan}", 1, $charge]];
                if ($net > 0) {
                    $result['invoice'] = $this->invoice($sub, 'adjustment', $at, $end, $lines, $at)->number;
                } elseif ($net < 0) {
                    $this->db()->table('subscriptions')->where('id', $sub->id)->update(['credit_balance' => $this->db()->raw('credit_balance + '.(-$net))]);
                    $result['credit_added'] = -$net;
                }
            }
            $update = ['plan' => $newPlan, 'updated_at' => $at];
            // PLAT-13: leaving the free trial is the conversion the funnel counts. Mid-trial, the paid plan starts (and is
            // first invoiced) when the trial period ends; after a lapsed trial it starts now.
            if ($sub->plan === 'trial' && $newPlan !== 'trial' && ! $sub->converted_at) {
                $update['converted_at'] = $at;
            }
            if ($sub->status === 'expired') {
                $update += ['status' => 'active', 'period_start' => $at, 'period_end' => $at->addMonthNoOverflow()];
            }
            $this->db()->table('subscriptions')->where('id', $sub->id)->update($update);
            $t->update(['plan' => $newPlan, 'trial_ends_at' => $newPlan === 'trial' ? $t->trial_ends_at : null]);
            if ($sub->status === 'expired') {
                $fresh = $this->db()->table('subscriptions')->where('id', $sub->id)->first();
                $result['invoice'] = $this->invoice($fresh, 'period', $at, $at->addMonthNoOverflow(), [['base', "Subscription: {$newPlan}", 1, $new]], $at)->number;
            }

            return $result;
        });
    }

    // ---- metering --------------------------------------------------------------------------------------------------

    /** @return array{users:int,storage_bytes:int} usage in [start,end) from the ENG-24 daily gauges */
    public function usage(int $tenantId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $q = fn (string $m) => $this->db()->table('tenant_usage_daily')->where('tenant_id', $tenantId)->where('metric', $m)
            ->where('day', '>=', $start->toDateString())->where('day', '<', $end->toDateString());

        return ['users' => (int) $q('users')->max('value'), 'storage_bytes' => (int) ($q('storage_bytes')->orderByDesc('day')->value('value') ?? 0)];
    }

    private function overageLines(object $sub, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $limits = $this->catalog->set($sub->plan)->limits;
        $use = $this->usage((int) $sub->tenant_id, $start, $end);
        $lines = [];
        $cur = $sub->currency;
        if (($limits['users'] ?? null) !== null && $use['users'] > $limits['users']) {
            $extra = $use['users'] - (int) $limits['users'];
            $unit = (int) config("billing.overage.users.{$cur}", 0);
            $unit > 0 && $lines[] = ['overage_users', "Extra users above {$limits['users']} (peak {$use['users']})", $extra, $unit];
        }
        if (($limits['storage_bytes'] ?? null) !== null && $use['storage_bytes'] > $limits['storage_bytes']) {
            $extraGb = (int) ceil(($use['storage_bytes'] - (int) $limits['storage_bytes']) / (1024 ** 3));
            $unit = (int) config("billing.overage.storage_gb.{$cur}", 0);
            $unit > 0 && $lines[] = ['overage_storage', 'Extra storage above '.intdiv((int) $limits['storage_bytes'], 1024 ** 3).' GB', $extraGb, $unit];
        }

        return $lines;
    }

    // ---- invoicing -------------------------------------------------------------------------------------------------

    /** Roll every due subscription forward, invoicing each new period. @return int invoices created */
    /**
     * Invoice every subscription whose period ends on or before $now. With $preBill the periods may lie in the future
     * (an operator invoicing ahead): such invoices are dated the day their period starts, and a trial or a subscription
     * set to cancel is left alone, because its state must only change on the real date.
     */
    public function runDue(?CarbonImmutable $now = null, bool $preBill = false): int
    {
        $now ??= $this->now();
        $made = 0;
        foreach ($this->db()->table('subscriptions')->whereNotIn('status', ['canceled', 'expired'])->where('period_end', '<=', $now)->pluck('id') as $id) {
            for ($i = 0; $i < 24; $i++) {   // catch up after downtime, bounded
                $made += $this->rollOne((int) $id, $now, $preBill) ? 1 : 0;
                $s = $this->db()->table('subscriptions')->find($id);
                if (in_array($s->status, ['canceled', 'expired'], true) || CarbonImmutable::parse($s->period_end)->gt($now)) {
                    break;
                }
            }
        }

        return $made;
    }

    private function rollOne(int $id, CarbonImmutable $now, bool $preBill = false): bool
    {
        return (bool) $this->db()->transaction(function () use ($id, $now, $preBill) {
            $sub = $this->db()->table('subscriptions')->where('id', $id)->lockForUpdate()->first();
            $start = CarbonImmutable::parse($sub->period_start);
            $end = CarbonImmutable::parse($sub->period_end);
            if ($end->gt($now) || $sub->status === 'canceled') {
                return false;
            }
            if ($preBill && ($sub->cancel_at_period_end || ($sub->status === 'trialing' && $sub->plan === 'trial'))) {
                return false;
            }
            // PLAT-13: a free trial nobody converted ends here; it is not rolled into another free month.
            if ($sub->status === 'trialing' && $sub->plan === 'trial') {
                $this->db()->table('subscriptions')->where('id', $id)->update(['status' => 'expired', 'expired_at' => $now, 'updated_at' => $now]);

                return false;
            }
            if ($sub->cancel_at_period_end) {
                $this->db()->table('subscriptions')->where('id', $id)->update(['status' => 'canceled', 'updated_at' => $now]);

                return false;
            }
            $next = $end->addMonthNoOverflow();
            $lines = [['base', "Subscription: {$sub->plan}", 1, $this->price($sub->plan, $sub->currency)]];
            foreach ($this->overageLines($sub, $start, $end) as $l) {
                $lines[] = $l;
            }
            $inv = $this->invoice($sub, 'period', $end, $next, $lines, $preBill && $end->gt($this->now()) ? $end : $now, onDuplicate: 'skip');
            $this->db()->table('subscriptions')->where('id', $id)->update([
                'period_start' => $end, 'period_end' => $next, 'status' => $sub->status === 'trialing' ? 'active' : $sub->status, 'updated_at' => $now,
            ]);

            return $inv !== null;
        });
    }

    /**
     * A one-off charge outside the monthly cycle (setup, training, a manual overage). Issued as an `adjustment` invoice
     * on the firm's subscription, with the same numbering, credit and tax rules as every other invoice.
     */
    public function charge(Tenant $t, string $description, int $amount, int $quantity = 1, ?CarbonImmutable $at = null): object
    {
        $sub = $this->subscription($t) ?? throw new BillingException('That firm has no subscription to invoice. Subscribe it to a plan first.');
        if ($amount < 1 || $quantity < 1) {
            throw new BillingException('Enter an amount and quantity above zero.');
        }
        $at ??= $this->now();
        try {
            return $this->db()->transaction(fn () => $this->invoice($sub, 'adjustment', $at, $at, [['charge', $description, $quantity, $amount]], $at));
        } catch (BillingException $e) {
            throw new BillingException('An invoice was just issued for this firm; wait a moment and try again.');
        }
    }

    /** @param list<array{0:string,1:string,2:int,3:int}> $lines kind, description, quantity, unit amount */
    private function invoice(object $sub, string $kind, CarbonImmutable $start, CarbonImmutable $end, array $lines, CarbonImmutable $issued, string $onDuplicate = 'fail'): ?object
    {
        if ($this->db()->table('billing_invoices')->where('subscription_id', $sub->id)->where('kind', $kind)->where('period_start', $start)->exists()) {
            if ($onDuplicate === 'skip') {
                return null;
            }
            throw new BillingException('That period is already invoiced.');
        }
        $subtotal = array_sum(array_map(fn ($l) => $l[2] * $l[3], $lines));
        $balance = (int) $this->db()->table('subscriptions')->where('id', $sub->id)->value('credit_balance');
        $credit = max(0, min($balance, $subtotal));
        $net = $subtotal - $credit;
        $tax = Money::tax($net, (int) config('billing.tax_rate_bp'));
        $total = $net + $tax;

        $number = $this->nextNumber($issued);
        $id = $this->db()->table('billing_invoices')->insertGetId([
            'number' => $number, 'subscription_id' => $sub->id, 'tenant_id' => $sub->tenant_id, 'kind' => $kind, 'period_start' => $start, 'period_end' => $end,
            'currency' => $sub->currency, 'subtotal' => $subtotal, 'credit_applied' => $credit, 'tax' => $tax, 'total' => $total,
            'amount_paid' => 0, 'status' => $total === 0 ? 'paid' : 'open', 'issued_at' => $issued->toDateString(),
            'due_at' => $issued->addDays((int) config('billing.payment_terms_days'))->toDateString(), 'created_at' => $issued, 'updated_at' => $issued,
        ]);
        foreach ($lines as [$k, $d, $q, $u]) {
            $this->db()->table('billing_invoice_lines')->insert(['invoice_id' => $id, 'kind' => $k, 'description' => $d, 'quantity' => $q, 'unit_amount' => $u, 'amount' => $q * $u, 'created_at' => $issued, 'updated_at' => $issued]);
        }
        if ($credit > 0) {
            $this->db()->table('billing_invoice_lines')->insert(['invoice_id' => $id, 'kind' => 'credit', 'description' => 'Credit applied', 'quantity' => 1, 'unit_amount' => -$credit, 'amount' => -$credit, 'created_at' => $issued, 'updated_at' => $issued]);
            $this->db()->table('subscriptions')->where('id', $sub->id)->update(['credit_balance' => $this->db()->raw('credit_balance - '.$credit)]);
        }

        return $this->db()->table('billing_invoices')->find($id);
    }

    private function nextNumber(CarbonImmutable $at): string
    {
        $this->db()->table('billing_counters')->insertOrIgnore(['name' => 'invoice', 'value' => 0]);
        $this->db()->table('billing_counters')->where('name', 'invoice')->update(['value' => $this->db()->raw('value + 1')]);
        $n = (int) $this->db()->table('billing_counters')->where('name', 'invoice')->value('value');

        return sprintf('BI-%s-%06d', $at->format('Y'), $n);
    }

    // ---- payments --------------------------------------------------------------------------------------------------

    /** @return string applied | duplicate */
    public function recordPayment(string $invoiceNumber, int $amount, string $currency, string $provider, string $ref, ?CarbonImmutable $at = null): string
    {
        $at ??= $this->now();

        return $this->db()->transaction(function () use ($invoiceNumber, $amount, $currency, $provider, $ref, $at) {
            $inv = $this->db()->table('billing_invoices')->where('number', $invoiceNumber)->lockForUpdate()->first()
                ?? throw new BillingException("No such invoice [{$invoiceNumber}].");
            if ($amount <= 0) {
                throw new BillingException('Payment must be positive.');
            }
            if ($inv->currency !== strtoupper($currency)) {
                throw new BillingException("Invoice is in {$inv->currency}, payment in {$currency}.");
            }
            if ($inv->status === 'void') {
                throw new BillingException('That invoice is void.');
            }
            if ($this->db()->table('billing_payments')->where('provider', $provider)->where('provider_ref', $ref)->exists()) {
                return 'duplicate';
            }
            $this->db()->table('billing_payments')->insert(['invoice_id' => $inv->id, 'provider' => $provider, 'provider_ref' => $ref, 'amount' => $amount, 'currency' => $inv->currency, 'received_at' => $at, 'created_at' => $at, 'updated_at' => $at]);

            $paid = (int) $inv->amount_paid + $amount;
            $update = ['amount_paid' => $paid, 'updated_at' => $at];
            if ($paid >= (int) $inv->total) {
                $update = ['amount_paid' => (int) $inv->total, 'status' => 'paid', 'updated_at' => $at];
                $excess = $paid - (int) $inv->total;
                $excess > 0 && $this->db()->table('subscriptions')->where('id', $inv->subscription_id)->update(['credit_balance' => $this->db()->raw('credit_balance + '.$excess)]);
            }
            $this->db()->table('billing_invoices')->where('id', $inv->id)->update($update);
            $this->settle((int) $inv->subscription_id, $at);

            return 'applied';
        });
    }

    /** After a payment: clear past_due and undo a billing suspension once nothing is overdue. */
    private function settle(int $subId, CarbonImmutable $at): void
    {
        $overdue = $this->db()->table('billing_invoices')->where('subscription_id', $subId)->where('status', 'open')->where('due_at', '<', $at->toDateString())->exists();
        $sub = $this->db()->table('subscriptions')->find($subId);
        if ($overdue) {
            return;
        }
        $sub->status === 'past_due' && $this->db()->table('subscriptions')->where('id', $subId)->update(['status' => 'active']);
        if ($sub->suspended_by_billing) {
            $tenant = Tenant::find($sub->tenant_id);
            if ($tenant && $tenant->status === 'suspended') {
                app(TenantLifecycle::class)->resume($tenant);
            }
            $this->db()->table('subscriptions')->where('id', $subId)->update(['suspended_by_billing' => false]);
        }
    }

    // ---- dunning ---------------------------------------------------------------------------------------------------

    /** @return array{reminders:int,past_due:int,suspended:int} */
    public function dun(?CarbonImmutable $now = null): array
    {
        $now ??= $this->now();
        $r = ['reminders' => 0, 'past_due' => 0, 'suspended' => 0];
        $steps = array_values((array) config('billing.dunning.reminder_days'));
        $suspendAfter = (int) config('billing.dunning.suspend_after_days');

        $open = $this->db()->table('billing_invoices')->where('status', 'open')->where('due_at', '<', $now->toDateString())->get();
        foreach ($open as $inv) {
            $days = (int) CarbonImmutable::parse($inv->due_at)->startOfDay()->diffInDays($now->startOfDay());
            $sub = $this->db()->table('subscriptions')->find($inv->subscription_id);
            if ($sub->status === 'canceled') {
                continue;
            }
            if ($sub->status === 'active' || $sub->status === 'trialing') {
                $this->db()->table('subscriptions')->where('id', $sub->id)->update(['status' => 'past_due', 'updated_at' => $now]);
                $r['past_due']++;
            }
            $crossed = count(array_filter($steps, fn ($d) => $days >= $d));
            if ($crossed > (int) $inv->dunning_step) {
                // claim the step first so two overlapping runs cannot both send
                $claimed = $this->db()->table('billing_invoices')->where('id', $inv->id)->where('dunning_step', $inv->dunning_step)->update(['dunning_step' => $crossed]);
                if ($claimed && $sub->billing_email) {
                    $paybill = $inv->currency === 'KES' ? (string) config('billing.mpesa.shortcode') : '';
                    Mail::to($sub->billing_email)->send(new DunningNotice($inv->number, sprintf('%s %.2f', $inv->currency, ($inv->total - $inv->amount_paid) / 100), $inv->due_at, $days, $suspendAfter > 0,
                        app(Collection::class)->payUrl($inv->number), $paybill !== '' ? $paybill : null));
                    $r['reminders']++;
                }
            }
            if ($suspendAfter > 0 && $days >= $suspendAfter && ! $sub->suspended_by_billing) {
                $tenant = Tenant::find($sub->tenant_id);
                if ($tenant && $tenant->status === 'active') {
                    app(TenantLifecycle::class)->suspend($tenant);
                    $this->db()->table('subscriptions')->where('id', $sub->id)->update(['suspended_by_billing' => true, 'updated_at' => $now]);
                    $r['suspended']++;
                }
            }
        }

        return $r;
    }
}
