<?php

namespace App\Http\Controllers;

use App\Billing\Billing;
use App\Billing\BillingException;
use App\Platform\PlatformAudit;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** PLAT-4: billing in the platform console. Staff act on a firm's subscription; every change is audited with its reason. */
class PlatformBillingController extends Controller
{
    public function __construct(private Billing $billing)
    {
    }

    private function db()
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    private function view(Tenant $t, bool $includeAutopayUrl = false): array
    {
        $sub = $this->billing->subscription($t);
        $invoices = $sub ? $this->db()->table('billing_invoices')->where('subscription_id', $sub->id)->orderByDesc('id')->limit(50)->get() : collect();

        $invoices = $invoices->map(fn ($i) => (object) ((array) $i + ['amount_due' => max(0, (int) $i->total - (int) $i->amount_paid)]))->values();
        $result = ['subscription' => $sub, 'invoices' => $invoices];
        // A signed card-setup URL is a short-lived payment capability, not ordinary read data.
        // Only staff who can operate billing may retrieve it.
        if ($includeAutopayUrl) {
            $result['autopay_url'] = $sub && ! $sub->autopay ? app(\App\Billing\Autopay::class)->setupUrl((int) $sub->id) : null;
        }

        return $result;
    }

    public function show(Request $r, string $slug): JsonResponse
    {
        $staff = $r->attributes->get('platform_staff');

        return response()->json(['data' => $this->view(Tenant::where('slug', $slug)->firstOrFail(), $staff instanceof \App\Platform\PlatformStaff && $staff->can('operate'))]);
    }

    /** Every firm's subscription, with what the plan list adds up to each month. ?status= narrows it. */
    public function subscriptions(Request $r): JsonResponse
    {
        \App\Billing\PlanStore::sync();
        $q = $this->db()->table('subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->select('s.id', 's.plan', 's.currency', 's.provider', 's.status', 's.period_end', 's.trial_ends_at', 's.cancel_at_period_end', 's.autopay', 's.credit_balance', 't.slug', 't.name as firm', 't.status as firm_status')
            ->orderBy('t.name');
        if ($r->filled('status')) {
            $q->where('s.status', (string) $r->query('status'));
        }
        $rows = $q->limit(500)->get()->map(fn ($row) => (object) ((array) $row + ['price' => config("billing.plans.{$row->plan}.{$row->currency}")]));

        $mrr = [];
        foreach ($rows as $row) {
            if (in_array($row->status, ['active', 'past_due'], true)) {
                $price = config("billing.plans.{$row->plan}.{$row->currency}");
                if ($price) {
                    $mrr[$row->currency] = ($mrr[$row->currency] ?? 0) + (int) $price;
                }
            }
        }

        return response()->json(['data' => $rows, 'summary' => [
            'by_status' => $this->db()->table('subscriptions')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'monthly_recurring' => $mrr,
        ]]);
    }

    /** Every subscription invoice. ?status=open|paid|void|overdue (overdue = open and past its due date). */
    public function invoices(Request $r): JsonResponse
    {
        $q = $this->db()->table('billing_invoices as i')->join('tenants as t', 't.id', '=', 'i.tenant_id')
            ->select('i.number', 'i.kind', 'i.currency', 'i.total', 'i.amount_paid', 'i.status', 'i.issued_at', 'i.due_at', 't.slug', 't.name as firm')
            ->orderByDesc('i.id');
        $status = (string) $r->query('status', '');
        if ($status === 'overdue') {
            $q->where('i.status', 'open')->where('i.due_at', '<', now()->toDateString());
        } elseif ($status !== '') {
            $q->where('i.status', $status);
        }
        $rows = $q->limit(200)->get()->map(fn ($i) => (object) ((array) $i + ['amount_due' => $i->status === 'open' ? max(0, (int) $i->total - (int) $i->amount_paid) : 0]));

        $open = [];
        $overdue = [];
        $today = now()->toDateString();
        foreach ($this->db()->table('billing_invoices')->where('status', 'open')->get(['currency', 'total', 'amount_paid', 'due_at']) as $i) {
            $due = max(0, (int) $i->total - (int) $i->amount_paid);
            $open[$i->currency] = ($open[$i->currency] ?? 0) + $due;
            if ($i->due_at < $today) {
                $overdue[$i->currency] = ($overdue[$i->currency] ?? 0) + $due;
            }
        }

        return response()->json(['data' => $rows, 'summary' => ['open' => $open, 'overdue' => $overdue]]);
    }

    public function invoice(string $number): JsonResponse
    {
        $inv = $this->db()->table('billing_invoices')->where('number', $number)->first() ?? abort(404);

        return response()->json(['data' => ['invoice' => $inv,
            'lines' => $this->db()->table('billing_invoice_lines')->where('invoice_id', $inv->id)->get(),
            'payments' => $this->db()->table('billing_payments')->where('invoice_id', $inv->id)->get(),
            'pay_url' => $inv->status === 'open' ? app(\App\Billing\Collection::class)->payUrl($number) : null]]);   // PLAT-4: to send to the firm
    }

    /**
     * PLAT-13: the self-service funnel over the last ?days= (default 30, max 365): signups submitted -> email verified ->
     * firm ready, then trials -> converted to a paid plan, or lapsed. The conversion rate counts only decided trials
     * (converted or lapsed), so trials still running do not drag it down.
     */
    public function funnel(Request $r): JsonResponse
    {
        $days = max(1, min(365, (int) $r->query('days', 30)));
        $since = now()->subDays($days);
        $signups = $this->db()->table('signups')->where('created_at', '>=', $since);
        $trials = $this->db()->table('subscriptions')->whereNotNull('trial_ends_at')->where('created_at', '>=', $since);

        $converted = (clone $trials)->whereNotNull('converted_at')->get(['created_at', 'converted_at']);
        $lapsed = (clone $trials)->whereNull('converted_at')->where('status', 'expired')->count();
        $toConvert = $converted->map(fn ($s) => \Carbon\CarbonImmutable::parse($s->created_at)->diffInHours(\Carbon\CarbonImmutable::parse($s->converted_at)) / 24)->sort()->values();
        $decided = $converted->count() + $lapsed;

        return response()->json(['data' => [
            'days' => $days,
            'signups' => [
                'submitted' => (clone $signups)->count(),
                'verified' => (clone $signups)->whereNotNull('verified_at')->count(),
                'ready' => (clone $signups)->where('status', 'ready')->count(),
                'failed' => (clone $signups)->where('status', 'failed')->count(),
            ],
            'trials' => [
                'started' => (clone $trials)->count(),
                'running' => (clone $trials)->where('status', 'trialing')->where('plan', 'trial')->count(),
                'converted' => $converted->count(),
                'lapsed' => $lapsed,
                'conversion_rate' => $decided > 0 ? round($converted->count() / $decided, 4) : null,
                'median_days_to_convert' => $toConvert->isEmpty() ? null : round((float) $toConvert[intdiv($toConvert->count(), 2)], 1),
            ],
        ]]);
    }

    public function subscribe(\App\Http\Requests\PlatformBilling\SubscribeRequest $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $d = $r->validated();

        return $this->act($r, 'billing.subscribe', $slug, $d, fn () => $this->billing->subscribe($t, $d['plan'], $d['currency'], $d['provider'] ?? 'manual', $d['billing_email'] ?? null, (int) ($d['trial_days'] ?? 0)), 201, $t);
    }

    public function changePlan(\App\Http\Requests\PlatformBilling\ChangePlanRequest $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $d = $r->validated();

        return $this->act($r, 'billing.change_plan', $slug, $d, fn () => $this->billing->changePlan($t, $d['plan']), 200, $t);
    }

    public function cancel(\App\Http\Requests\PlatformBilling\CancelRequest $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $d = $r->validated();

        return $this->act($r, 'billing.cancel', $slug, $d, fn () => $this->billing->cancel($t), 200, $t);
    }

    /** Issue a one-off invoice to a firm (setup fee, training, anything outside the monthly cycle). `amount` is in minor units. */
    public function charge(\App\Http\Requests\Platform\ChargeRequest $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $d = $r->validated();

        return $this->act($r, 'billing.charge', $slug, $d, fn () => $this->billing->charge($t, $d['description'], (int) $d['amount'], (int) ($d['quantity'] ?? 1)), 201, $t);
    }

    /** Issue every invoice that has fallen due now (the same job the nightly `billing:run` does). */
    /** Invoice every period that ends on or before `through` (default today). A later date invoices upcoming months ahead of time. */
    public function runNow(\App\Http\Requests\Platform\RunBillingRequest $r): JsonResponse
    {
        $staff = $r->attributes->get('platform_staff');
        $d = $r->validated();
        $today = $this->billing->now()->startOfDay();
        $through = isset($d['through']) ? \Carbon\CarbonImmutable::parse($d['through'])->endOfDay() : $today->endOfDay();
        $ahead = $through->gt($today->endOfDay());
        $made = $ahead ? $this->billing->runDue($through, true) : $this->billing->runDue();
        PlatformAudit::record($staff, 'billing.run', null, ['through' => $through->toDateString()], 'ok', (string) $made, $r->ip());

        return response()->json(['data' => ['invoices_issued' => $made, 'through' => $through->toDateString()]]);
    }

    /** Record a payment received outside a provider (bank transfer, cash). `reference` makes it idempotent. */
    public function pay(\App\Http\Requests\PlatformBilling\PayRequest $r, string $number): JsonResponse
    {
        $d = $r->validated();
        $inv = $this->db()->table('billing_invoices')->where('number', $number)->first() ?? abort(404);
        $slug = Tenant::find($inv->tenant_id)?->slug;
        $staff = $r->attributes->get('platform_staff');
        try {
            $res = $this->billing->recordPayment($number, (int) $d['amount'], $d['currency'], 'manual', $d['reference']);
        } catch (BillingException $e) {
            PlatformAudit::record($staff, 'billing.payment', $slug, $d + ['invoice' => $number], 'failed', $e->getMessage(), $r->ip());

            return response()->json(['message' => $e->getMessage()], 422);
        }
        PlatformAudit::record($staff, 'billing.payment', $slug, $d + ['invoice' => $number], 'ok', $res, $r->ip());

        return response()->json(['data' => ['result' => $res, 'invoice' => $this->db()->table('billing_invoices')->where('number', $number)->first()]]);
    }

    /** PLAT-4: ask the firm's phone for an M-Pesa payment of an open KES invoice (the result arrives at the STK callback). */
    public function mpesa(\App\Http\Requests\PlatformBilling\MpesaRequest $r, \App\Billing\Collection $collection, string $number): JsonResponse
    {
        $d = $r->validated();
        $inv = $this->db()->table('billing_invoices')->where('number', $number)->first() ?? abort(404);
        $slug = Tenant::find($inv->tenant_id)?->slug;
        $staff = $r->attributes->get('platform_staff');
        $params = ['invoice' => $number, 'reason' => $d['reason']];   // the phone number is not written to the audit trail
        try {
            $res = $collection->stkPush($number, $d['phone']);
        } catch (BillingException $e) {
            PlatformAudit::record($staff, 'billing.mpesa_request', $slug, $params, 'failed', $e->getMessage(), $r->ip());

            return response()->json(['message' => $e->getMessage()], 422);
        }
        PlatformAudit::record($staff, 'billing.mpesa_request', $slug, $params, 'ok', $res['checkout_request_id'], $r->ip());

        return response()->json(['data' => $res], 202);
    }

    /** PLAT-4: stop automatic payment for a firm and remove its saved card (audited). */
    public function disableAutopay(\App\Http\Requests\PlatformBilling\ReasonRequest $r, \App\Billing\Autopay $autopay, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $sub = $this->billing->subscription($t) ?? abort(404);

        return $this->act($r, 'billing.autopay_off', $slug, ['reason' => $r->validated('reason')], fn () => $autopay->disable((int) $sub->id), 200, $t);
    }

    private function act(Request $r, string $action, string $slug, array $params, \Closure $fn, int $status, Tenant $t): JsonResponse
    {
        $staff = $r->attributes->get('platform_staff');
        try {
            $out = $fn();
        } catch (BillingException $e) {
            PlatformAudit::record($staff, $action, $slug, $params, 'failed', $e->getMessage(), $r->ip());

            return response()->json(['message' => $e->getMessage()], 422);
        }
        PlatformAudit::record($staff, $action, $slug, $params, 'ok', null, $r->ip());

        return response()->json(['data' => $this->view($t->fresh()) + ['result' => $out]], $status);
    }
}
