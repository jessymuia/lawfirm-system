<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\BillingException;
use App\Billing\Money;
use App\Billing\Webhooks;
use App\Mail\DunningNotice;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-4: subscriptions, proration, metered overage, payments, dunning, signed webhooks. Money is integer minor units. */
class BillingTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    private Billing $billing;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat4-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test', 'tenancy.platform_mfa' => 'optional',
            'billing.tax_rate_bp' => 0, 'billing.payment_terms_days' => 14, 'billing.dunning.suspend_after_days' => 0,
            'billing.stripe.webhook_secret' => 'whsec_test', 'billing.mpesa.callback_token' => 'tok-123', 'billing.mpesa.shortcode' => '174379',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        $this->billing = app(Billing::class);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function tenant(string $slug = 'acme', string $plan = 'trial'): Tenant
    {
        return Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => 'active', 'plan' => $plan, 'database' => "{$this->dir}/{$slug}.sqlite", 'schema_version' => 'x', 'migration_status' => 'ok']);
    }

    private function t(string $s): C
    {
        return C::parse($s);
    }

    private function ctl()
    {
        return DB::connection('control');
    }

    private function usage(Tenant $t, string $day, string $metric, int $value): void
    {
        $this->ctl()->table('tenant_usage_daily')->insert(['tenant_id' => $t->id, 'day' => $day, 'metric' => $metric, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sub(Tenant $t)
    {
        return $this->ctl()->table('subscriptions')->where('tenant_id', $t->id)->first();
    }

    private function invoices(Tenant $t)
    {
        return $this->ctl()->table('billing_invoices')->where('tenant_id', $t->id)->orderBy('id')->get();
    }

    // ---- money -----------------------------------------------------------------------------------------------------

    public function test_money_rounds_half_up_in_integers(): void
    {
        $this->assertSame(258065, Money::ratio(500000, 16, 31));    // 258064.516
        $this->assertSame(1290323, Money::ratio(2500000, 16, 31));  // 1290322.58
        $this->assertSame(1, Money::ratio(1, 1, 2));                // exact half rounds up
        $this->assertSame(0, Money::ratio(1, 1, 3));
        $this->assertSame(-258065, Money::ratio(-500000, 16, 31));  // symmetrical for credits
        $this->assertSame(160000, Money::tax(1000000, 1600));
        $this->assertSame(0, Money::tax(-5, 1600));
        $this->assertSame(0, Money::tax(1000, 0));
        $this->expectException(BillingException::class);
        Money::ratio(1, 1, 0);
    }

    // ---- subscribing -----------------------------------------------------------------------------------------------

    public function test_subscribing_invoices_the_first_period_and_sets_the_plan(): void
    {
        $t = $this->tenant();
        $s = $this->billing->subscribe($t, 'starter', 'KES', 'manual', 'billing@acme.test', 0, $this->t('2026-01-10 09:00'));
        $this->assertSame('active', $s->status);
        $this->assertSame('starter', $t->fresh()->plan);
        $inv = $this->invoices($t);
        $this->assertCount(1, $inv);
        $this->assertSame(500000, (int) $inv[0]->total);
        $this->assertSame('open', $inv[0]->status);
        $this->assertSame('2026-01-24', $inv[0]->due_at);                         // 14 days terms
        $this->assertMatchesRegularExpression('/^BI-2026-\d{6}$/', $inv[0]->number);
        $this->assertSame('2026-02-10 09:00:00', $this->sub($t)->period_end);
    }

    public function test_bad_subscriptions_are_refused(): void
    {
        $t = $this->tenant();
        foreach ([['nonsense', 'KES'], ['starter', 'EUR'], ['self_hosted', 'KES']] as [$plan, $cur]) {
            try {
                $this->billing->subscribe($t, $plan, $cur);
                $this->fail("{$plan}/{$cur} should be refused");
            } catch (BillingException) {
                $this->assertNull($this->sub($t));
            }
        }
        $this->billing->subscribe($t, 'starter', 'KES');
        $this->expectException(BillingException::class);
        $this->billing->subscribe($t, 'professional', 'KES');       // one subscription per firm
    }

    public function test_a_trial_costs_nothing_and_the_first_invoice_comes_when_it_ends(): void
    {
        $t = $this->tenant();
        $s = $this->billing->subscribe($t, 'professional', 'USD', 'manual', null, 14, $this->t('2026-03-01 00:00'));
        $this->assertSame('trialing', $s->status);
        $this->assertCount(0, $this->invoices($t));

        $this->assertSame(0, $this->billing->runDue($this->t('2026-03-10 00:00')));       // still trialing
        $this->assertSame(1, $this->billing->runDue($this->t('2026-03-15 00:00')));
        $inv = $this->invoices($t);
        $this->assertCount(1, $inv);
        $this->assertSame(19900, (int) $inv[0]->total);
        $this->assertSame('2026-03-15 00:00:00', $inv[0]->period_start);
        $this->assertSame('active', $this->sub($t)->status);
    }

    // ---- periods, metering -----------------------------------------------------------------------------------------

    public function test_rolling_a_period_bills_the_next_base_plus_last_periods_overage_and_is_idempotent(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));   // 5 users, 5 GB included
        $this->usage($t, '2026-01-05', 'users', 8);        // peak 8 -> 3 extra
        $this->usage($t, '2026-01-20', 'users', 6);
        $this->usage($t, '2026-01-31', 'storage_bytes', 6 * 1024 ** 3 + 1);   // 1 GB + 1 byte over -> 2 GB billed (rounded up)
        $this->usage($t, '2025-12-31', 'users', 99);       // before the period: ignored
        $this->usage($t, '2026-02-01', 'users', 99);       // after the period: ignored

        $this->assertSame(1, $this->billing->runDue($this->t('2026-02-01 00:00')));
        $this->assertSame(0, $this->billing->runDue($this->t('2026-02-01 00:00')));        // idempotent
        $this->assertCount(2, $this->invoices($t));
        $inv = $this->invoices($t)[1];
        $lines = $this->ctl()->table('billing_invoice_lines')->where('invoice_id', $inv->id)->get()->keyBy('kind');
        $this->assertSame(500000, (int) $lines['base']->amount);
        $this->assertSame(3 * 80000, (int) $lines['overage_users']->amount);
        $this->assertSame(3, (int) $lines['overage_users']->quantity);
        $this->assertSame(2 * 20000, (int) $lines['overage_storage']->amount);
        $this->assertSame(500000 + 240000 + 40000, (int) $inv->total);
        $this->assertSame('2026-03-01 00:00:00', $this->sub($t)->period_end);
    }

    public function test_within_limits_or_unlimited_plans_have_no_overage(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'enterprise', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $this->usage($t, '2026-01-05', 'users', 500);
        $this->usage($t, '2026-01-05', 'storage_bytes', 900 * 1024 ** 3);
        $this->billing->runDue($this->t('2026-02-01 00:00'));
        $this->assertSame(9000000, (int) $this->invoices($t)[1]->total);

        $u = $this->tenant('beta');
        $this->billing->subscribe($u, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $this->usage($u, '2026-01-05', 'users', 5);      // exactly the limit
        $this->billing->runDue($this->t('2026-02-01 00:00'));
        $this->assertSame(500000, (int) $this->invoices($u)[1]->total);
        $this->assertSame(0, $this->ctl()->table('billing_invoice_lines')->where('invoice_id', $this->invoices($u)[1]->id)->where('kind', 'overage_users')->count());   // no zero-quantity line
    }

    public function test_a_rerun_after_a_crash_between_invoicing_and_advancing_does_not_double_bill(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $this->billing->runDue($this->t('2026-02-01 00:00'));
        // pretend the process died after the invoice was written but before the period moved on
        $this->ctl()->table('subscriptions')->where('tenant_id', $t->id)->update(['period_start' => '2026-01-01 00:00:00', 'period_end' => '2026-02-01 00:00:00']);
        $this->assertSame(0, $this->billing->runDue($this->t('2026-02-01 00:00')));
        $this->assertCount(2, $this->invoices($t));
        $this->assertSame('2026-03-01 00:00:00', $this->sub($t)->period_end);      // and the period did advance
    }

    public function test_missed_periods_are_caught_up_one_invoice_each(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $this->assertSame(3, $this->billing->runDue($this->t('2026-04-15 00:00')));        // Feb, Mar, Apr periods
        $this->assertCount(4, $this->invoices($t));
        $this->assertSame('2026-05-01 00:00:00', $this->sub($t)->period_end);
        $this->assertSame(4, $this->invoices($t)->pluck('number')->unique()->count());     // distinct numbers
    }

    public function test_cancel_at_period_end_stops_billing(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $this->billing->cancel($t);
        $this->assertSame(0, $this->billing->runDue($this->t('2026-02-01 00:00')));
        $this->assertSame('canceled', $this->sub($t)->status);
        $this->assertCount(1, $this->invoices($t));
        $this->assertSame(0, $this->billing->runDue($this->t('2026-06-01 00:00')));
    }

    // ---- proration -------------------------------------------------------------------------------------------------

    public function test_an_upgrade_credits_unused_time_and_charges_the_rest_exactly(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));    // period to 2026-02-01 = 31 days
        $r = $this->billing->changePlan($t, 'professional', $this->t('2026-01-16 00:00'));                    // 16 days left

        $adj = $this->invoices($t)[1];
        $this->assertSame($r['invoice'], $adj->number);
        $this->assertSame('adjustment', $adj->kind);
        $this->assertSame(1290323 - 258065, (int) $adj->total);                                              // 1,032,258
        $lines = $this->ctl()->table('billing_invoice_lines')->where('invoice_id', $adj->id)->get()->keyBy('kind');
        $this->assertSame(-258065, (int) $lines['proration_credit']->amount);
        $this->assertSame(1290323, (int) $lines['proration_charge']->amount);
        $this->assertSame('professional', $t->fresh()->plan);              // entitlements follow immediately
        $this->assertSame('professional', $this->sub($t)->plan);

        $this->billing->runDue($this->t('2026-02-01 00:00'));              // next period is at the new price
        $this->assertSame(2500000, (int) $this->invoices($t)[2]->total);
    }

    public function test_a_downgrade_becomes_credit_that_the_next_invoice_consumes(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'professional', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $r = $this->billing->changePlan($t, 'starter', $this->t('2026-01-16 00:00'));
        $this->assertNull($r['invoice']);
        $this->assertSame(1290323 - 258065, $r['credit_added']);
        $this->assertSame(1032258, (int) $this->sub($t)->credit_balance);
        $this->assertCount(1, $this->invoices($t));

        $this->billing->runDue($this->t('2026-02-01 00:00'));              // starter 500,000 fully covered by the credit
        $inv = $this->invoices($t)[1];
        $this->assertSame(500000, (int) $inv->subtotal);
        $this->assertSame(500000, (int) $inv->credit_applied);
        $this->assertSame(0, (int) $inv->total);
        $this->assertSame('paid', $inv->status);                           // nothing to collect
        $this->assertSame(532258, (int) $this->sub($t)->credit_balance);
    }

    public function test_plan_change_edge_cases(): void
    {
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 14, $this->t('2026-01-01 00:00'));
        $this->billing->changePlan($t, 'professional', $this->t('2026-01-05 00:00'));      // during a trial: just switch, nothing charged
        $this->assertCount(0, $this->invoices($t));
        $this->assertSame('professional', $t->fresh()->plan);
        try {
            $this->billing->changePlan($t, 'professional');
            $this->fail('same plan');
        } catch (BillingException) {
        }
        try {
            $this->billing->changePlan($t, 'self_hosted');
            $this->fail('unpriced plan');
        } catch (BillingException) {
        }
        $this->assertSame('professional', $t->fresh()->plan);
    }

    public function test_tax_is_applied_to_the_net_amount_after_credit(): void
    {
        config(['billing.tax_rate_bp' => 1600]);
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));
        $inv = $this->invoices($t)[0];
        $this->assertSame(500000, (int) $inv->subtotal);
        $this->assertSame(80000, (int) $inv->tax);
        $this->assertSame(580000, (int) $inv->total);
    }

    public function test_credit_reduces_the_taxable_amount(): void
    {
        config(['billing.tax_rate_bp' => 1600]);
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-01 00:00'));      // invoice 1: 580,000 incl. tax
        $this->ctl()->table('subscriptions')->where('tenant_id', $t->id)->update(['credit_balance' => 200000]);
        $this->billing->runDue($this->t('2026-02-01 00:00'));
        $inv = $this->invoices($t)[1];
        $this->assertSame([500000, 200000, 48000, 348000], [(int) $inv->subtotal, (int) $inv->credit_applied, (int) $inv->tax, (int) $inv->total]);   // 16% of 300,000
    }

    // ---- payments --------------------------------------------------------------------------------------------------

    private function openInvoice(string $plan = 'starter', string $cur = 'KES', string $slug = 'acme', string $at = '2026-01-01 00:00'): object
    {
        $t = Tenant::where('slug', $slug)->first() ?? $this->tenant($slug);
        $this->billing->subscribe($t, $plan, $cur, 'manual', 'billing@acme.test', 0, $this->t($at));

        return $this->invoices($t)->last();
    }

    public function test_payments_partial_full_over_and_replayed(): void
    {
        $inv = $this->openInvoice();                                          // 500,000
        $this->assertSame('applied', $this->billing->recordPayment($inv->number, 200000, 'KES', 'manual', 'bank-1'));
        $this->assertSame('open', $this->ctl()->table('billing_invoices')->find($inv->id)->status);

        $this->assertSame('duplicate', $this->billing->recordPayment($inv->number, 200000, 'KES', 'manual', 'bank-1'));     // replay
        $this->assertSame(200000, (int) $this->ctl()->table('billing_invoices')->find($inv->id)->amount_paid);

        $this->assertSame('applied', $this->billing->recordPayment($inv->number, 350000, 'KES', 'manual', 'bank-2'));       // 50,000 too much
        $row = $this->ctl()->table('billing_invoices')->find($inv->id);
        $this->assertSame('paid', $row->status);
        $this->assertSame(500000, (int) $row->amount_paid);
        $this->assertSame(50000, (int) $this->ctl()->table('subscriptions')->value('credit_balance'));     // the excess is credit, not lost
    }

    public function test_bad_payments_are_refused_and_change_nothing(): void
    {
        $inv = $this->openInvoice();
        foreach ([[0, 'KES'], [-5, 'KES'], [1000, 'USD']] as [$amt, $cur]) {
            try {
                $this->billing->recordPayment($inv->number, $amt, $cur, 'manual', 'x'.$amt.$cur);
                $this->fail('refused');
            } catch (BillingException) {
            }
        }
        $this->ctl()->table('billing_invoices')->where('id', $inv->id)->update(['status' => 'void']);
        try {
            $this->billing->recordPayment($inv->number, 1000, 'KES', 'manual', 'late');
            $this->fail('void');
        } catch (BillingException) {
        }
        $this->assertSame(0, $this->ctl()->table('billing_payments')->count());
        $this->expectException(BillingException::class);
        $this->billing->recordPayment('BI-0000-000000', 1, 'KES', 'manual', 'nope');
    }

    // ---- dunning ---------------------------------------------------------------------------------------------------

    public function test_dunning_reminders_go_out_once_per_step_and_mark_the_firm_past_due(): void
    {
        Mail::fake();
        $inv = $this->openInvoice();                                           // due 2026-01-15
        $t = Tenant::first();

        $this->assertSame(0, $this->billing->dun($this->t('2026-01-15 12:00'))['reminders']);    // not yet overdue
        $this->assertSame('active', $this->sub($t)->status);

        $r = $this->billing->dun($this->t('2026-01-19 12:00'));                // 4 days overdue: step 1 (3 days)
        $this->assertSame(1, $r['reminders']);
        $this->assertSame(1, $r['past_due']);
        $this->assertSame('past_due', $this->sub($t)->status);
        $this->assertSame(0, $this->billing->dun($this->t('2026-01-19 18:00'))['reminders']);    // same day: nothing more
        $this->assertSame(0, $this->billing->dun($this->t('2026-01-21 12:00'))['reminders']);    // 6 days: still step 1

        $this->assertSame(1, $this->billing->dun($this->t('2026-01-22 12:00'))['reminders']);    // 7 days: step 2
        $this->assertSame(1, $this->billing->dun($this->t('2026-01-29 12:00'))['reminders']);    // 14 days: step 3
        $this->assertSame(0, $this->billing->dun($this->t('2026-03-01 12:00'))['reminders']);    // no step 4
        Mail::assertSent(DunningNotice::class, 3);
        Mail::assertSent(DunningNotice::class, fn ($m) => $m->hasTo('billing@acme.test') && $m->number === $inv->number);
    }

    public function test_a_late_run_sends_one_notice_not_one_per_missed_step(): void
    {
        Mail::fake();
        $this->openInvoice();
        $this->billing->dun($this->t('2026-02-20 12:00'));                    // 36 days overdue: all three steps crossed at once
        Mail::assertSent(DunningNotice::class, 1);
        $this->assertSame(3, (int) $this->ctl()->table('billing_invoices')->value('dunning_step'));
    }

    public function test_no_suspension_unless_configured_and_payment_lifts_it_without_touching_data(): void
    {
        Mail::fake();
        $inv = $this->openInvoice();
        $t = Tenant::first();
        $this->billing->dun($this->t('2026-03-01 00:00'));                     // 45 days overdue, suspend_after_days = 0
        $this->assertSame('active', $t->fresh()->status);

        config(['billing.dunning.suspend_after_days' => 30]);
        $r = $this->billing->dun($this->t('2026-03-01 00:00'));
        $this->assertSame(1, $r['suspended']);
        $this->assertSame('suspended', $t->fresh()->status);
        $this->assertTrue((bool) $this->sub($t)->suspended_by_billing);
        $this->assertSame(0, $this->billing->dun($this->t('2026-03-02 00:00'))['suspended']);    // not twice

        $this->billing->recordPayment($inv->number, 500000, 'KES', 'manual', 'bank-9', $this->t('2026-03-03 00:00'));
        $this->assertSame('active', $t->fresh()->status);                      // resumed
        $this->assertFalse((bool) $this->sub($t)->suspended_by_billing);
        $this->assertSame('active', $this->sub($t)->status);
    }

    public function test_a_firm_suspended_by_staff_is_not_resumed_by_a_payment(): void
    {
        $inv = $this->openInvoice();
        $t = Tenant::first();
        $t->update(['status' => 'suspended']);                                 // a human decision, not billing
        $this->billing->recordPayment($inv->number, 500000, 'KES', 'manual', 'p1', $this->t('2026-01-20 00:00'));
        $this->assertSame('suspended', $t->fresh()->status);
    }

    public function test_paying_one_invoice_while_another_is_overdue_keeps_the_firm_past_due(): void
    {
        Mail::fake();
        $inv1 = $this->openInvoice();
        $t = Tenant::first();
        $this->billing->runDue($this->t('2026-02-01 00:00'));                  // invoice 2 issued, due 2026-02-15
        $this->billing->dun($this->t('2026-02-20 00:00'));
        $this->assertSame('past_due', $this->sub($t)->status);
        $this->billing->recordPayment($inv1->number, 500000, 'KES', 'manual', 'p1', $this->t('2026-02-20 00:00'));
        $this->assertSame('past_due', $this->sub($t)->status);                 // invoice 2 is still overdue
    }

    // ---- stripe ----------------------------------------------------------------------------------------------------

    private function stripeEvent(string $number, array $over = [], string $id = 'evt_1'): array
    {
        return ['id' => $id, 'type' => 'checkout.session.completed', 'data' => ['object' => $over + ['id' => 'cs_1', 'client_reference_id' => $number, 'payment_status' => 'paid', 'amount_total' => 500000, 'currency' => 'kes', 'payment_intent' => 'pi_1']]];
    }

    private function sign(string $body, ?int $t = null, string $secret = 'whsec_test'): string
    {
        $t ??= time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    private function postStripe(array $event, ?string $sig = null, ?string $body = null)
    {
        $body ??= json_encode($event);

        return $this->call('POST', self::HOST.'/api/platform/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sig ?? $this->sign($body)], $body);
    }

    public function test_the_stripe_signature_rules(): void
    {
        $b = '{"a":1}';
        $now = 1_800_000_000;
        $sig = fn ($t, $s = 'sec') => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$b}", $s);
        $this->assertTrue(Webhooks::stripeSignatureValid($b, $sig($now), 'sec', 300, $now));
        $this->assertTrue(Webhooks::stripeSignatureValid($b, $sig($now).',v1=deadbeef', 'sec', 300, $now));    // any matching v1
        $this->assertFalse(Webhooks::stripeSignatureValid($b.' ', $sig($now), 'sec', 300, $now));              // tampered body
        $this->assertFalse(Webhooks::stripeSignatureValid($b, $sig($now, 'other'), 'sec', 300, $now));         // wrong secret
        $this->assertFalse(Webhooks::stripeSignatureValid($b, $sig($now - 301), 'sec', 300, $now));            // stale: replay
        $this->assertFalse(Webhooks::stripeSignatureValid($b, $sig($now + 301), 'sec', 300, $now));
        $this->assertTrue(Webhooks::stripeSignatureValid($b, $sig($now - 300), 'sec', 300, $now));
        $this->assertFalse(Webhooks::stripeSignatureValid($b, '', 'sec', 300, $now));
        $this->assertFalse(Webhooks::stripeSignatureValid($b, 'v1=abc', 'sec', 300, $now));                      // no timestamp
        $this->assertFalse(Webhooks::stripeSignatureValid($b, 't=abc,v1=x', 'sec', 300, $now));
        $this->assertFalse(Webhooks::stripeSignatureValid($b, $sig($now, ''), '', 300, $now));                  // no secret configured: reject all
    }

    public function test_a_signed_checkout_event_pays_the_invoice_and_a_replay_does_not_pay_twice(): void
    {
        $inv = $this->openInvoice();
        $this->postStripe($this->stripeEvent($inv->number))->assertOk()->assertJsonPath('outcome', 'applied');
        $this->assertSame('paid', $this->ctl()->table('billing_invoices')->find($inv->id)->status);

        $this->postStripe($this->stripeEvent($inv->number))->assertOk()->assertJsonPath('outcome', 'duplicate');
        // same payment arriving under a different event id is still the same payment (payment_intent)
        $this->postStripe($this->stripeEvent($inv->number, [], 'evt_2'))->assertOk()->assertJsonPath('outcome', 'duplicate');
        $this->assertSame(1, $this->ctl()->table('billing_payments')->count());
        $this->assertSame(500000, (int) $this->ctl()->table('billing_invoices')->find($inv->id)->amount_paid);
    }

    public function test_forged_or_unsigned_stripe_calls_do_nothing(): void
    {
        $inv = $this->openInvoice();
        $body = json_encode($this->stripeEvent($inv->number));
        $this->postStripe([], 'garbage', $body)->assertStatus(400);
        $this->postStripe([], $this->sign($body, null, 'wrong-secret'), $body)->assertStatus(400);
        $this->postStripe([], $this->sign($body, time() - 4000), $body)->assertStatus(400);
        $this->postJson(self::HOST.'/api/platform/webhooks/stripe', json_decode($body, true))->assertStatus(400);
        config(['billing.stripe.webhook_secret' => '']);
        $this->postStripe([], null, $body)->assertStatus(400);                 // unconfigured: never accept
        $this->assertSame('open', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
        $this->assertSame(0, $this->ctl()->table('billing_payments')->count());
    }

    public function test_stripe_events_that_are_not_payments_or_do_not_match_are_recorded_not_applied(): void
    {
        $inv = $this->openInvoice();
        $this->postStripe(['id' => 'evt_x', 'type' => 'customer.created', 'data' => ['object' => []]])->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->postStripe($this->stripeEvent($inv->number, ['payment_status' => 'unpaid'], 'evt_u'))->assertOk()->assertJsonPath('outcome', 'ignored');
        $this->postStripe($this->stripeEvent('BI-2026-999999', [], 'evt_n'))->assertOk()->assertJsonPath('outcome', 'unmatched');
        $this->postStripe($this->stripeEvent($inv->number, ['currency' => 'usd', 'payment_intent' => 'pi_usd'], 'evt_c'))->assertOk()->assertJsonPath('outcome', 'unmatched');
        $this->postStripe(['type' => 'x'])->assertOk()->assertJsonPath('outcome', 'rejected');                  // no id
        $this->assertSame('open', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
        $this->assertSame(['ignored', 'ignored', 'unmatched', 'unmatched'], $this->ctl()->table('billing_events')->orderBy('id')->pluck('outcome')->all());
    }

    // ---- m-pesa ----------------------------------------------------------------------------------------------------

    private function mpesa(array $p, string $token = 'tok-123')
    {
        return $this->postJson(self::HOST."/api/platform/webhooks/mpesa/{$token}", $p + ['TransID' => 'QWE123', 'TransAmount' => '5000.00', 'BusinessShortCode' => '174379', 'BillRefNumber' => 'x']);
    }

    public function test_an_mpesa_paybill_payment_with_the_invoice_number_as_account_pays_it(): void
    {
        $inv = $this->openInvoice();
        $this->mpesa(['BillRefNumber' => $inv->number])->assertOk()->assertJson(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        $row = $this->ctl()->table('billing_invoices')->find($inv->id);
        $this->assertSame('paid', $row->status);                               // KES 5,000.00 = 500,000 minor units
        $this->mpesa(['BillRefNumber' => $inv->number])->assertOk();           // Safaricom retry
        $this->assertSame(1, $this->ctl()->table('billing_payments')->count());
        $this->assertSame('mpesa', $this->ctl()->table('billing_payments')->value('provider'));
    }

    public function test_mpesa_secret_amounts_and_unmatched_payments(): void
    {
        $inv = $this->openInvoice();
        $this->mpesa(['BillRefNumber' => $inv->number], 'wrong-token')->assertNotFound();
        config(['billing.mpesa.callback_token' => '']);
        $this->mpesa(['BillRefNumber' => $inv->number], '')->assertStatus(404);
        config(['billing.mpesa.callback_token' => 'tok-123']);
        $this->assertSame(0, $this->ctl()->table('billing_payments')->count());

        $this->mpesa(['BillRefNumber' => $inv->number, 'TransAmount' => '1234.5', 'TransID' => 'A1'])->assertOk();         // 123,450 minor
        $this->assertSame(123450, (int) $this->ctl()->table('billing_payments')->where('provider_ref', 'A1')->value('amount'));
        $this->mpesa(['BillRefNumber' => $inv->number, 'TransAmount' => '12.345', 'TransID' => 'A2'])->assertOk();         // malformed: rejected
        $this->mpesa(['BillRefNumber' => $inv->number, 'TransAmount' => '1e3', 'TransID' => 'A3'])->assertOk();
        $this->mpesa(['BillRefNumber' => $inv->number, 'BusinessShortCode' => '999999', 'TransID' => 'A4'])->assertOk();    // not our paybill
        $this->mpesa(['BillRefNumber' => 'NOT-AN-INVOICE', 'TransID' => 'A5'])->assertOk();                                  // customer typo
        $this->assertSame(1, $this->ctl()->table('billing_payments')->count());
        $this->assertSame(['applied', 'rejected', 'rejected', 'unmatched', 'unmatched'], $this->ctl()->table('billing_events')->orderBy('id')->pluck('outcome')->all());

        $usd = $this->openInvoice('starter', 'USD', 'usdco');                  // M-Pesa pays shillings only
        $this->mpesa(['BillRefNumber' => $usd->number, 'TransID' => 'A6'])->assertOk();
        $this->assertSame('open', $this->ctl()->table('billing_invoices')->find($usd->id)->status);
    }

    public function test_webhooks_answer_only_on_the_platform_host(): void
    {
        $inv = $this->openInvoice();
        Tenant::first()->domains()->create(['domain' => 'acme.app.test']);      // a real, active tenant host
        $this->postJson('http://acme.app.test/api/platform/webhooks/mpesa/tok-123', ['TransID' => 'Z', 'TransAmount' => '5000', 'BillRefNumber' => $inv->number, 'BusinessShortCode' => '174379'])->assertNotFound();
        $this->assertSame(0, $this->ctl()->table('billing_payments')->count());
        $this->postJson('http://acme.app.test/api/platform/webhooks/stripe', [])->assertNotFound();
    }

    // ---- platform console ------------------------------------------------------------------------------------------

    private function staffToken(string $role): string
    {
        $s = PlatformStaff::create(['name' => $role, 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    public function test_the_console_needs_the_right_role_and_audits_every_change(): void
    {
        $this->tenant();
        $viewer = $this->staffToken('viewer');
        $op = $this->staffToken('operator');
        $body = ['plan' => 'starter', 'currency' => 'KES', 'reason' => 'signed order form 123'];

        $this->withToken($viewer)->postJson(self::HOST.'/api/platform/tenants/acme/subscription', $body)->assertForbidden();
        $this->withToken($op)->postJson(self::HOST.'/api/platform/tenants/acme/subscription', ['plan' => 'starter', 'currency' => 'KES'])->assertStatus(422);        // reason needed
        $this->withToken($op)->postJson(self::HOST.'/api/platform/tenants/acme/subscription', $body)->assertCreated()->assertJsonPath('data.subscription.plan', 'starter');
        $this->withToken($op)->postJson(self::HOST.'/api/platform/tenants/acme/subscription', $body)->assertStatus(422);                                          // already subscribed

        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/tenants/acme/billing')->assertOk()->assertJsonCount(1, 'data.invoices');
        $this->withToken($viewer)->postJson(self::HOST.'/api/platform/tenants/acme/subscription/plan', ['plan' => 'professional', 'reason' => 'upgrade'])->assertForbidden();
        $this->withToken($op)->postJson(self::HOST.'/api/platform/tenants/acme/subscription/plan', ['plan' => 'professional', 'reason' => 'customer upgrade'])->assertOk()->assertJsonPath('data.subscription.plan', 'professional');
        $this->withToken($op)->postJson(self::HOST.'/api/platform/tenants/acme/subscription/cancel', ['reason' => 'customer asked'])->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', 1);

        $actions = PlatformAudit::orderBy('id')->pluck('result', 'action')->all();
        $this->assertArrayHasKey('billing.subscribe', $actions);
        $this->assertArrayHasKey('billing.change_plan', $actions);
        $this->assertArrayHasKey('billing.cancel', $actions);
        $this->assertSame('failed', PlatformAudit::where('action', 'billing.subscribe')->orderByDesc('id')->value('result'));    // the refused second attempt
    }

    public function test_staff_record_an_offline_payment_idempotently(): void
    {
        $inv = $this->openInvoice();
        $op = $this->staffToken('operator');
        $viewer = $this->staffToken('viewer');
        $url = self::HOST."/api/platform/billing/invoices/{$inv->number}/payments";
        $body = ['amount' => 500000, 'currency' => 'KES', 'reference' => 'EFT-77', 'reason' => 'bank statement 2026-01-12'];

        $this->withToken($viewer)->postJson($url, $body)->assertForbidden();
        $this->withToken($op)->postJson($url, $body)->assertOk()->assertJsonPath('data.result', 'applied')->assertJsonPath('data.invoice.status', 'paid');
        $this->withToken($op)->postJson($url, $body)->assertOk()->assertJsonPath('data.result', 'duplicate');
        $this->withToken($op)->postJson($url, ['currency' => 'USD', 'reference' => 'EFT-78'] + $body)->assertStatus(422);
        $this->withToken($viewer)->getJson(self::HOST."/api/platform/billing/invoices/{$inv->number}")->assertOk()->assertJsonCount(1, 'data.payments');
        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/billing/invoices/NOPE')->assertNotFound();
    }

    public function test_the_billing_command_invoices_and_duns_and_only_runs_in_saas(): void
    {
        Mail::fake();
        $t = $this->tenant();
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', 'b@acme.test', 0, C::now()->subMonths(2)->subDays(20));
        $this->artisan('billing:run')->expectsOutputToContain('Invoices created')->assertSuccessful();
        $this->assertGreaterThanOrEqual(2, $this->invoices($t)->count());
        config(['tenancy.mode' => 'single']);
        $this->artisan('billing:run')->expectsOutputToContain('multi-tenant')->assertSuccessful();
    }

    // ---- one-off charges and guards ----------------------------------------------------------------------------------

    public function test_a_one_off_charge_is_an_adjustment_invoice_and_is_not_issued_twice_at_once(): void
    {
        $t = $this->tenant();
        try {
            $this->billing->charge($t, 'Training', 15000);
            $this->fail('charged a firm without a subscription');
        } catch (BillingException $e) {
            $this->assertStringContainsString('no subscription', $e->getMessage());
        }
        $this->billing->subscribe($t, 'starter', 'KES', 'manual', null, 0, $this->t('2026-01-10 09:00'));
        foreach ([[0, 1], [15000, 0]] as [$amount, $quantity]) {
            try {
                $this->billing->charge($t, 'Training', $amount, $quantity);
                $this->fail("charged {$quantity} x {$amount}");
            } catch (BillingException $e) {
                $this->assertStringContainsString('above zero', $e->getMessage());
            }
        }

        $at = $this->t('2026-01-15 10:00');
        $inv = $this->billing->charge($t, 'Training', 15000, 2, $at);
        $this->assertSame('adjustment', $inv->kind);
        $this->assertSame(30000, (int) $inv->total);
        $this->assertSame('open', $inv->status);
        try {
            $this->billing->charge($t, 'Training again', 15000, 1, $at);    // a double click in the console
            $this->fail('issued two adjustments at the same moment');
        } catch (BillingException $e) {
            $this->assertStringContainsString('just issued', $e->getMessage());
        }
        $this->assertSame(1, $this->ctl()->table('billing_invoices')->where('kind', 'adjustment')->count());

        $this->assertSame('adjustment', $this->billing->charge($t, 'Setup', 5000)->kind);    // dated now when no date is given
    }

    public function test_unknown_providers_canceled_plan_changes_and_nameless_mpesa_calls_are_refused(): void
    {
        $t = $this->tenant();
        try {
            $this->billing->subscribe($t, 'starter', 'KES', 'paypal');
            $this->fail('subscribed through an unknown provider');
        } catch (BillingException $e) {
            $this->assertSame('Unknown provider.', $e->getMessage());
        }

        $this->billing->subscribe($t, 'starter', 'KES');
        $this->ctl()->table('subscriptions')->where('tenant_id', $t->id)->update(['status' => 'canceled']);
        try {
            $this->billing->changePlan($t, 'professional');
            $this->fail('changed the plan of a canceled subscription');
        } catch (BillingException $e) {
            $this->assertSame('The subscription is canceled.', $e->getMessage());
        }

        $this->assertSame(['outcome' => 'rejected', 'detail' => 'no TransID'], app(Webhooks::class)->mpesaC2B([]));

        config(['billing.trial.offboard_after_days' => 0]);    // offboarding lapsed trials switched off
        $this->assertSame([], $this->billing->offboardLapsedTrials());
    }
}
