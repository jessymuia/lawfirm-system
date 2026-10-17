<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Billing\BillingException;
use App\Billing\Collection;
use App\Billing\PlanStore;
use App\Entitlements\Entitlements;
use App\Entitlements\PlanCatalog;
use App\Http\Requests\Subscription\ChooseRequest;
use App\Security\SecurityPolicy;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PLAT-13: firm-side subscription management (manage-subscription): plan, trial, invoice and payment visibility, and
 * end-of-period cancellation. Choosing a plan during a trial takes effect at once and is first invoiced when the trial
 * ends; after a lapsed trial it starts now, and the firm is writable again immediately. Multi-tenant (SaaS) only.
 */
class SubscriptionController extends Controller
{
    public function __construct(private Billing $billing, private TenantManager $tenants) {}

    public function show(): JsonResponse
    {
        return $this->response(true, 'success', $this->view(), 200);
    }

    public function choose(ChooseRequest $r): JsonResponse
    {
        $tenant = $this->tenants->current() ?? abort(404);
        $before = $tenant->plan;
        try {
            $this->billing->subscription($tenant)
                ? $this->billing->changePlan($tenant, $r->validated('plan'))
                : $this->billing->subscribe($tenant, $r->validated('plan'), (string) config('billing.trial.currency', 'KES'), 'manual', $r->user()->email);
        } catch (BillingException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }
        SecurityPolicy::audit('subscription_plan_chosen', 'subscription', 0, ['plan' => $before], ['plan' => $tenant->fresh()->plan]);
        app(Entitlements::class)->forget();

        return $this->response(true, 'Your plan is now '.$r->validated('plan').'.', $this->view(), 200);
    }

    public function cancel(): JsonResponse
    {
        $tenant = $this->tenants->current() ?? abort(404);
        try {
            $subscription = $this->billing->cancel($tenant);
        } catch (BillingException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }

        SecurityPolicy::audit('subscription_cancel_scheduled', 'subscription', (int) $subscription->id, [
            'cancel_at_period_end' => false,
        ], ['cancel_at_period_end' => true]);

        return $this->response(true, 'Your subscription will end at the close of the current billing period.', $this->view(), 200);
    }

    private function view(): array
    {
        // a self-hosted install has no firm registry row (it has a license instead): nothing to show
        $tenant = $this->tenants->current() ?? abort(404);
        $sub = $this->billing->subscription($tenant);
        $currency = $sub->currency ?? (string) config('billing.trial.currency', 'KES');
        $ends = $tenant->trial_ends_at;
        $catalog = app(PlanCatalog::class);
        $invoices = collect();
        if ($sub) {
            $invoices = DB::connection(config('tenancy.control_connection'))
                ->table('billing_invoices')
                ->where('subscription_id', $sub->id)
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('issued_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(function ($invoice) {
                    $invoice->amount_due = max(0, (int) $invoice->total - (int) $invoice->amount_paid);
                    $invoice->pay_url = $invoice->status === 'open' && $invoice->amount_due > 0
                        ? app(Collection::class)->payUrl($invoice->number)
                        : null;

                    return $invoice;
                });
        }

        $plans = [];
        PlanStore::sync();
        foreach ((array) config('billing.plans') as $plan => $prices) {
            if ($plan === 'trial' || ! isset($prices[$currency]) || in_array($plan, (array) config('billing.archived_plans', []), true)) {
                continue;
            }
            $set = $catalog->set($plan);
            $plans[] = ['plan' => $plan, 'price' => $prices[$currency], 'currency' => $currency,
                'features' => array_keys(array_filter($set->features)), 'limits' => $set->limits];
        }

        return [
            'plan' => $tenant->plan,
            'status' => $sub->status ?? null,
            'currency' => $currency,
            'trial_ends_at' => $ends?->toIso8601String(),
            'trial_days_left' => $ends ? max(0, (int) ceil(now()->diffInSeconds($ends, false) / 86400)) : null,
            'trial_expired' => $ends !== null && now()->gte($ends),
            'period_end' => $sub->period_end ?? null,
            'cancel_at_period_end' => (bool) ($sub->cancel_at_period_end ?? false),
            'invoices' => $invoices,
            'mpesa_paybill' => filled(config('billing.mpesa.consumer_key'))
                && filled(config('billing.mpesa.consumer_secret'))
                && filled(config('billing.mpesa.shortcode'))
                && filled(config('billing.mpesa.callback_token'))
                ? config('billing.mpesa.shortcode')
                : null,
            'plans' => $plans,
        ];
    }
}
