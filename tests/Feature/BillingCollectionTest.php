<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Billing\Collection;
use App\Mail\DunningNotice;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * PLAT-4: the outbound side of billing with the platform's own accounts: Stripe Checkout through a signed pay link,
 * M-Pesa STK push and its callback, Daraja C2B registration, and the pay options in reminders. Requests are checked
 * against the providers' documented formats with faked HTTP (no live calls here).
 */
class BillingCollectionTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/plat4c-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test', 'tenancy.platform_mfa' => 'optional',
            'app.url' => 'https://app.test',
            'billing.tax_rate_bp' => 0, 'billing.payment_terms_days' => 14, 'billing.dunning.suspend_after_days' => 0,
            'billing.stripe.secret_key' => 'sk_test_platform', 'billing.stripe.webhook_secret' => 'whsec_test',
            'billing.mpesa.callback_token' => 'tok-123', 'billing.mpesa.shortcode' => '174379',
            'billing.mpesa.consumer_key' => 'ck', 'billing.mpesa.consumer_secret' => 'cs', 'billing.mpesa.passkey' => 'pk', 'billing.mpesa.environment' => 'sandbox',
            // a firm's own collection keys must never be used to bill the firm
            'services.stripe_collect.secret_key' => 'sk_firm', 'services.mpesa_collect.consumer_key' => 'firm-ck',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(\App\Tenancy\TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function ctl()
    {
        return DB::connection('control');
    }

    private function openInvoice(string $cur = 'KES', string $slug = 'acme'): object
    {
        $t = Tenant::create(['slug' => $slug, 'name' => ucfirst($slug), 'status' => 'active', 'plan' => 'trial', 'database' => "{$this->dir}/{$slug}.sqlite", 'schema_version' => 'x', 'migration_status' => 'ok']);
        app(Billing::class)->subscribe($t, 'starter', $cur, 'manual', 'billing@acme.test', 0, C::parse('2026-01-01'));

        return $this->ctl()->table('billing_invoices')->where('tenant_id', $t->id)->orderByDesc('id')->first();
    }

    private function fakeDaraja(array $stk = ['ResponseCode' => '0', 'CheckoutRequestID' => 'ws_CO_1', 'ResponseDescription' => 'Success']): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok-daraja', 'expires_in' => '3599']),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response($stk),
            'sandbox.safaricom.co.ke/mpesa/c2b/*' => Http::response(['OriginatorCoversationID' => 'x', 'ResponseCode' => '0', 'ResponseDescription' => 'Success']),
        ]);
    }

    private function staffToken(string $role): string
    {
        $s = PlatformStaff::create(['name' => $role, 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    public function test_the_pay_link_opens_a_checkout_for_the_balance_with_the_platform_account(): void
    {
        $inv = $this->openInvoice();
        app(Billing::class)->recordPayment($inv->number, 100000, 'KES', 'manual', 'part-1');   // 5,000.00 owed, 1,000.00 paid
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);

        $url = app(Collection::class)->payUrl($inv->number);
        $this->assertSame('https://admin.platform.test/api/platform/pay/'.$inv->number.'?sig='.Collection::signature($inv->number), $url);

        $this->get(str_replace('https://', 'http://', $url))->assertStatus(303)->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(function (SentRequest $r) use ($inv) {
            $this->assertSame('https://api.stripe.com/v1/checkout/sessions', $r->url());
            $this->assertSame(['Bearer sk_test_platform'], $r->header('Authorization'));
            $this->assertSame($inv->number, $r['client_reference_id']);   // what Webhooks::stripe matches on
            $this->assertSame(400000, (int) $r['line_items'][0]['price_data']['unit_amount']);
            $this->assertSame('kes', $r['line_items'][0]['price_data']['currency']);
            $this->assertSame('https://admin.platform.test/status', $r['success_url']);

            return true;
        });
    }

    public function test_the_pay_link_is_signed_and_harmless_once_paid_or_when_stripe_is_down(): void
    {
        $inv = $this->openInvoice();
        Http::fake(['api.stripe.com/*' => Http::response(['error' => ['message' => 'nope']], 500)]);

        $this->get(self::HOST."/api/platform/pay/{$inv->number}?sig=forged")->assertNotFound();
        $this->get(self::HOST."/api/platform/pay/{$inv->number}")->assertNotFound();
        $this->get(self::HOST."/api/platform/pay/{$inv->number}?sig=".Collection::signature($inv->number))->assertStatus(503);

        app(Billing::class)->recordPayment($inv->number, 500000, 'KES', 'manual', 'all');
        $this->get(self::HOST."/api/platform/pay/{$inv->number}?sig=".Collection::signature($inv->number))->assertOk()->assertSee('already paid');

        config(['billing.stripe.secret_key' => null]);
        $this->assertNull(app(Collection::class)->payUrl($inv->number));
        $this->get(self::HOST."/api/platform/pay/{$inv->number}?sig=".Collection::signature($inv->number))->assertNotFound();
        Tenant::first()->domains()->create(['domain' => 'acme.app.test']);
        $this->get("http://acme.app.test/api/platform/pay/{$inv->number}?sig=".Collection::signature($inv->number))->assertNotFound();
    }

    public function test_staff_send_an_stk_push_and_the_callback_pays_the_invoice_once(): void
    {
        $inv = $this->openInvoice();
        $this->fakeDaraja();
        $op = $this->staffToken('operator');
        $viewer = $this->staffToken('viewer');
        $url = self::HOST."/api/platform/billing/invoices/{$inv->number}/mpesa";

        $this->withToken($viewer)->postJson($url, ['phone' => '0712345678', 'reason' => 'customer asked on the phone'])->assertForbidden();
        $this->withToken($op)->postJson($url, ['phone' => '0712345678'])->assertStatus(422);   // reason needed
        $this->withToken($op)->postJson($url, ['phone' => '0712345678', 'reason' => 'customer asked on the phone'])
            ->assertStatus(202)->assertJsonPath('data.checkout_request_id', 'ws_CO_1')->assertJsonPath('data.amount', 5000);

        Http::assertSent(function (SentRequest $r) use ($inv) {
            if (! str_contains($r->url(), 'stkpush')) {
                return false;
            }
            $this->assertSame(['Bearer tok-daraja'], $r->header('Authorization'));
            $this->assertSame('254712345678', $r['PhoneNumber']);
            $this->assertSame('174379', $r['BusinessShortCode']);
            $this->assertSame(5000, $r['Amount']);
            $this->assertSame(base64_encode('174379pk'.$r['Timestamp']), $r['Password']);
            $this->assertSame("https://admin.platform.test/api/platform/webhooks/stk/tok-123/{$inv->number}", $r['CallBackURL']);
            $this->assertStringNotContainsStringIgnoringCase('mpesa', parse_url($r['CallBackURL'], PHP_URL_PATH), 'Daraja refuses such URLs');

            return true;
        });
        Http::assertSent(fn (SentRequest $r) => str_contains($r->url(), '/oauth/') && $r->header('Authorization') === ['Basic '.base64_encode('ck:cs')]);
        $audit = PlatformAudit::where('action', 'billing.mpesa_request')->where('result', 'ok')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('0712345678', json_encode($audit->getAttributes()), 'the phone number stays out of the audit trail');

        $callback = ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm1', 'CheckoutRequestID' => 'ws_CO_1', 'ResultCode' => 0, 'ResultDesc' => 'Processed',
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => 5000], ['Name' => 'MpesaReceiptNumber', 'Value' => 'RKT1'], ['Name' => 'PhoneNumber', 'Value' => 254712345678]]]]]];
        $this->postJson(self::HOST."/api/platform/webhooks/stk/wrong/{$inv->number}", $callback)->assertNotFound();
        $this->postJson(self::HOST."/api/platform/webhooks/stk/tok-123/{$inv->number}", $callback)->assertOk()->assertJson(['ResultCode' => 0]);
        $this->postJson(self::HOST."/api/platform/webhooks/stk/tok-123/{$inv->number}", $callback)->assertOk();   // Safaricom retry
        $this->assertSame('paid', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
        $this->assertSame(1, $this->ctl()->table('billing_payments')->where('provider_ref', 'RKT1')->count());
    }

    public function test_a_cancelled_prompt_is_recorded_and_pays_nothing_and_bad_requests_are_refused(): void
    {
        $inv = $this->openInvoice();
        $this->postJson(self::HOST."/api/platform/webhooks/stk/tok-123/{$inv->number}", ['Body' => ['stkCallback' => ['CheckoutRequestID' => 'ws_CO_2', 'ResultCode' => 1032, 'ResultDesc' => 'Request cancelled by user']]])->assertOk();
        $this->assertSame('open', $this->ctl()->table('billing_invoices')->find($inv->id)->status);
        $this->assertSame('ignored', $this->ctl()->table('billing_events')->where('event_id', 'ws_CO_2')->value('outcome'));

        $collection = app(Collection::class);
        $usd = $this->openInvoice('USD', 'usdco');
        foreach ([[$usd->number, '0712345678', 'KES invoices'], [$inv->number, '12345', 'Kenyan mobile'], ['BI-NOPE', '0712345678', 'No such invoice']] as [$number, $phone, $why]) {
            try {
                $collection->stkPush($number, $phone);
                $this->fail("expected a refusal: {$why}");
            } catch (\App\Billing\BillingException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
        $this->fakeDaraja(['errorCode' => '400.002.02', 'errorMessage' => 'Bad Request - Invalid PhoneNumber']);
        $this->expectExceptionMessage('Invalid PhoneNumber');
        $collection->stkPush($inv->number, '0712345678');
    }

    public function test_c2b_registration_points_daraja_at_a_url_it_accepts(): void
    {
        $this->fakeDaraja();
        $this->assertSame(0, Artisan::call('billing:mpesa-register'));
        Http::assertSent(function (SentRequest $r) {
            if (! str_contains($r->url(), '/c2b/v2/registerurl')) {
                return false;
            }
            $this->assertSame('174379', $r['ShortCode']);
            $this->assertSame('https://admin.platform.test/api/platform/webhooks/c2b/tok-123', $r['ConfirmationURL']);

            return true;
        });
        // the registered URL is a working confirmation endpoint
        $inv = $this->openInvoice();
        $this->postJson(self::HOST.'/api/platform/webhooks/c2b/tok-123', ['TransID' => 'C2B1', 'TransAmount' => '5000.00', 'BusinessShortCode' => '174379', 'BillRefNumber' => $inv->number])->assertOk();
        $this->assertSame('paid', $this->ctl()->table('billing_invoices')->find($inv->id)->status);

        config(['billing.mpesa.consumer_key' => null]);
        $this->assertSame(1, Artisan::call('billing:mpesa-register'));
    }

    public function test_reminders_carry_the_pay_link_and_paybill_and_the_console_shows_the_link(): void
    {
        Mail::fake();
        $inv = $this->openInvoice();
        app(Billing::class)->dun(C::parse('2026-01-25'));

        Mail::assertSent(DunningNotice::class, function (DunningNotice $m) use ($inv) {
            $html = $m->content()->htmlString;
            $this->assertStringContainsString(e(app(Collection::class)->payUrl($inv->number)), $html);
            $this->assertStringContainsString('paybill <b>174379</b>, account number <b>'.$inv->number.'</b>', $html);

            return true;
        });
        $viewer = $this->staffToken('viewer');
        $this->withToken($viewer)->getJson(self::HOST."/api/platform/billing/invoices/{$inv->number}")->assertOk()
            ->assertJsonPath('data.pay_url', app(Collection::class)->payUrl($inv->number));
    }

    public function test_refusals_when_not_configured_void_or_daraja_says_no(): void
    {
        $inv = $this->openInvoice();
        $collection = app(Collection::class);
        $this->assertSame(['outcome' => 'rejected', 'detail' => 'no CheckoutRequestID'], $collection->stkCallback($inv->number, ['Body' => []]));

        $this->ctl()->table('billing_invoices')->where('id', $inv->id)->update(['status' => 'void']);
        try {
            $collection->checkout($inv->number);
            $this->fail('a void invoice cannot be paid');
        } catch (\App\Billing\BillingException $e) {
            $this->assertStringContainsString('void', $e->getMessage());
        }

        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::sequence()->push(['errorMessage' => 'Invalid credentials'], 400)->push(['access_token' => 't']),
            'sandbox.safaricom.co.ke/mpesa/c2b/*' => Http::response(['errorMessage' => 'Urls are already registered'], 400),
        ]);
        $this->assertSame(1, Artisan::call('billing:mpesa-register'));
        $this->assertStringContainsString('refused the credentials (HTTP 400)', Artisan::output());
        $this->assertSame(1, Artisan::call('billing:mpesa-register'));
        $this->assertStringContainsString('Urls are already registered', Artisan::output());

        config(['billing.mpesa.passkey' => null]);
        $this->expectExceptionMessage('not configured');
        $collection->stkPush($inv->number, '0712345678');
    }
}
