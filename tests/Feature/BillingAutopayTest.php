<?php

namespace Tests\Feature;

use App\Billing\Autopay;
use App\Billing\Billing;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\OwnsTenancy;
use Tests\TestCase;

/**
 * PLAT-4: saved cards and automatic charging of subscription invoices with the platform's Stripe account. Requests are
 * checked against Stripe's documented API (faked HTTP; no live calls here).
 */
class BillingAutopayTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/autopay-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test', 'tenancy.platform_mfa' => 'optional',
            'app.url' => 'https://app.test', 'billing.tax_rate_bp' => 0, 'billing.payment_terms_days' => 14,
            'billing.stripe.secret_key' => 'sk_test_platform', 'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.autopay.max_attempts' => 3, 'billing.autopay.retry_days' => 3,
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function ctl()
    {
        return DB::connection('control');
    }

    /** @return array{0: object, 1: object} subscription, its open invoice (KES 5,000.00) */
    private function subscribed(): array
    {
        $t = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active', 'plan' => 'trial', 'database' => "{$this->dir}/acme.sqlite", 'schema_version' => 'x', 'migration_status' => 'ok']);
        app(Billing::class)->subscribe($t, 'starter', 'KES', 'manual', 'billing@acme.test', 0, C::parse('2026-01-01'));

        return [$this->ctl()->table('subscriptions')->first(), $this->ctl()->table('billing_invoices')->first()];
    }

    private function signedPost(array $event)
    {
        $body = json_encode($event);
        $t = time();

        return $this->call('POST', self::HOST.'/api/platform/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", 'whsec_test')], $body);
    }

    /** A saved card, as the setup webhook leaves it. */
    private function withCard(object $sub): void
    {
        $this->ctl()->table('subscriptions')->where('id', $sub->id)->update(['stripe_customer_id' => 'cus_1', 'stripe_payment_method_id' => 'pm_1', 'card_label' => 'visa •••• 4242', 'autopay' => true]);
    }

    private function staffToken(string $role): string
    {
        $s = PlatformStaff::create(['name' => $role, 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    public function test_the_firm_saves_a_card_through_a_signed_link_and_the_webhook_turns_autopay_on(): void
    {
        [$sub] = $this->subscribed();
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_set', 'url' => 'https://checkout.stripe.com/c/setup/cs_set']),
            'api.stripe.com/v1/setup_intents/*' => Http::response(['id' => 'seti_1', 'status' => 'succeeded',
                'payment_method' => ['id' => 'pm_1', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 12, 'exp_year' => 2030]]]),
            'api.stripe.com/v1/customers/cus_1' => Http::response(['id' => 'cus_1']),
        ]);
        $viewer = $this->staffToken('viewer');
        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/tenants/acme/billing')->assertOk()
            ->assertJsonMissingPath('data.autopay_url')
            ->assertJsonCount(1, 'data.invoices');
        $operator = $this->staffToken('operator');
        $url = $this->withToken($operator)->getJson(self::HOST.'/api/platform/tenants/acme/billing')->assertOk()->json('data.autopay_url');
        $this->assertSame('https://admin.platform.test/api/platform/autopay/'.$sub->id.'?sig='.Autopay::signature((int) $sub->id), $url);

        $this->get(self::HOST."/api/platform/autopay/{$sub->id}?sig=forged")->assertNotFound();
        $this->get(str_replace('https://', 'http://', $url))->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/setup/cs_set');
        $this->get(str_replace('https://', 'http://', $url))->assertStatus(303);
        Http::assertSentCount(3);   // one customer, two sessions: the customer is created once
        Http::assertSent(fn (SentRequest $r) => str_ends_with($r->url(), '/checkout/sessions') && $r['mode'] === 'setup' && $r['customer'] === 'cus_1'
            && $r['client_reference_id'] === "autopay:{$sub->id}" && $r->header('Authorization') === ['Bearer sk_test_platform']);
        Http::assertSent(fn (SentRequest $r) => str_ends_with($r->url(), '/v1/customers') && $r['email'] === 'billing@acme.test' && $r->header('Idempotency-Key') === ["customer-{$sub->id}"]);

        $this->signedPost(['id' => 'evt_setup', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'id' => 'cs_set', 'mode' => 'setup', 'customer' => 'cus_1', 'setup_intent' => 'seti_1', 'client_reference_id' => "autopay:{$sub->id}"]]])->assertOk();
        $saved = $this->ctl()->table('subscriptions')->find($sub->id);
        $this->assertSame('pm_1', $saved->stripe_payment_method_id);
        $this->assertSame('visa •••• 4242, expires 12/2030', $saved->card_label);
        $this->assertSame(1, (int) $saved->autopay);
        Http::assertSent(fn (SentRequest $r) => str_ends_with($r->url(), '/customers/cus_1') && $r['invoice_settings']['default_payment_method'] === 'pm_1');
        $this->assertNull($this->withToken($operator)->getJson(self::HOST.'/api/platform/tenants/acme/billing')->json('data.autopay_url'), 'no setup link once a card is saved');

        // a setup for somebody else's customer is not applied
        $this->signedPost(['id' => 'evt_other', 'type' => 'checkout.session.completed', 'data' => ['object' => [
            'mode' => 'setup', 'customer' => 'cus_someone_else', 'setup_intent' => 'seti_2', 'client_reference_id' => "autopay:{$sub->id}"]]])->assertOk();
        $this->assertSame('unmatched', $this->ctl()->table('billing_events')->where('event_id', 'evt_other')->value('outcome'));
    }

    public function test_billing_run_charges_the_saved_card_once_and_the_webhook_is_then_a_duplicate(): void
    {
        [$sub, $inv] = $this->subscribed();
        $this->withCard($sub);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_auto', 'status' => 'succeeded', 'amount' => 500000, 'currency' => 'kes'])]);

        $this->assertSame(['charged' => 1, 'failed' => 0, 'skipped' => 0], app(Autopay::class)->chargeDue(C::parse('2026-01-02')));
        Http::assertSent(function (SentRequest $r) use ($inv) {
            $this->assertSame(500000, (int) $r['amount']);
            $this->assertSame('kes', $r['currency']);
            $this->assertSame(['cus_1', 'pm_1', 'true', 'true'], [$r['customer'], $r['payment_method'], $r['off_session'], $r['confirm']]);
            $this->assertSame($inv->number, $r['metadata']['invoice']);
            $this->assertSame(["autopay-{$inv->number}-1"], $r->header('Idempotency-Key'));

            return true;
        });
        $this->assertSame('paid', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
        $this->assertSame('pi_auto', $this->ctl()->table('billing_payments')->value('provider_ref'));

        $this->signedPost(['id' => 'evt_pi', 'type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => 'pi_auto', 'amount_received' => 500000, 'currency' => 'kes', 'metadata' => ['invoice' => $inv->number]]]])->assertOk();
        $this->assertSame(1, $this->ctl()->table('billing_payments')->count(), 'the webhook does not pay twice');
        $this->assertSame(['charged' => 0, 'failed' => 0, 'skipped' => 0], app(Autopay::class)->chargeDue(C::parse('2026-01-09')), 'nothing left to charge');
    }

    public function test_a_declined_card_is_retried_days_apart_up_to_the_limit_and_reminders_carry_on(): void
    {
        [$sub, $inv] = $this->subscribed();
        $this->withCard($sub);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'insufficient_funds', 'message' => 'Your card has insufficient funds.']], 402)]);
        $autopay = app(Autopay::class);

        $this->assertSame(1, $autopay->chargeDue(C::parse('2026-01-02'))['failed']);
        $this->assertSame(1, $autopay->chargeDue(C::parse('2026-01-03'))['skipped'], 'waits retry_days before trying again');
        $this->assertSame(1, $autopay->chargeDue(C::parse('2026-01-05'))['failed']);
        $this->assertSame(1, $autopay->chargeDue(C::parse('2026-01-08'))['failed']);
        $this->assertSame(['charged' => 0, 'failed' => 0, 'skipped' => 0], $autopay->chargeDue(C::parse('2026-01-20')), 'no fourth attempt');
        Http::assertSentCount(3);
        $this->assertSame(3, (int) $this->ctl()->table('billing_invoices')->find($inv->id)->autopay_attempts);
        $this->assertSame(3, $this->ctl()->table('billing_events')->where('type', 'autopay')->where('outcome', 'failed')->count());
        $this->assertStringStartsWith('insufficient_funds:', $this->ctl()->table('billing_events')->where('type', 'autopay')->value('detail'));
        $this->assertSame('open', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
    }

    public function test_a_card_that_needs_its_holder_is_not_retried(): void
    {
        [$sub, $inv] = $this->subscribed();
        $this->withCard($sub);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['code' => 'authentication_required', 'message' => 'This payment requires authentication.']], 402)]);

        $this->assertSame(1, app(Autopay::class)->chargeDue(C::parse('2026-01-02'))['failed']);
        $this->assertSame(['charged' => 0, 'failed' => 0, 'skipped' => 0], app(Autopay::class)->chargeDue(C::parse('2026-01-10')));
        Http::assertSentCount(1);
    }

    public function test_staff_turn_autopay_off_and_the_card_is_removed(): void
    {
        [$sub] = $this->subscribed();
        $this->withCard($sub);
        Http::fake(['api.stripe.com/v1/payment_methods/pm_1/detach' => Http::response(['id' => 'pm_1'])]);
        $op = $this->staffToken('operator');
        $viewer = $this->staffToken('viewer');

        $this->withToken($viewer)->deleteJson(self::HOST.'/api/platform/tenants/acme/autopay', ['reason' => 'firm asked by email'])->assertForbidden();
        $this->withToken($op)->deleteJson(self::HOST.'/api/platform/tenants/acme/autopay', [])->assertStatus(422);
        $this->withToken($op)->deleteJson(self::HOST.'/api/platform/tenants/acme/autopay', ['reason' => 'firm asked by email'])->assertOk();

        Http::assertSent(fn (SentRequest $r) => str_ends_with($r->url(), '/payment_methods/pm_1/detach'));
        $after = $this->ctl()->table('subscriptions')->find($sub->id);
        $this->assertSame([0, null, null], [(int) $after->autopay, $after->stripe_payment_method_id, $after->card_label]);
        $this->assertSame('ok', PlatformAudit::where('action', 'billing.autopay_off')->value('result'));
    }

    public function test_without_stripe_nothing_is_attempted(): void
    {
        [$sub] = $this->subscribed();
        $this->withCard($sub);
        config(['billing.stripe.secret_key' => null]);
        Http::fake();
        $this->assertSame(['charged' => 0, 'failed' => 0, 'skipped' => 0], app(Autopay::class)->chargeDue(C::parse('2026-01-02')));
        $this->assertNull(app(Autopay::class)->setupUrl((int) $sub->id));
        $this->get(self::HOST."/api/platform/autopay/{$sub->id}?sig=".Autopay::signature((int) $sub->id))->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_billing_run_charges_saved_cards_before_reminders(): void
    {
        Carbon::setTestNow('2026-01-02 03:00:00');
        try {
            [$sub, $inv] = $this->subscribed();
            $this->withCard($sub);
            Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_run', 'status' => 'succeeded', 'amount' => 500000, 'currency' => 'kes'])]);
            $this->assertSame(0, Artisan::call('billing:run'));
            $this->assertStringContainsString('Automatic payments: 1 charged, 0 declined', Artisan::output());
            $this->assertSame('paid', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
        } finally {
            Carbon::setTestNow();
        }
    }
}
