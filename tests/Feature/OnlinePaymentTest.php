<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\SendSmsJob;
use App\Models\Cases;
use App\Models\Invoice;
use App\Models\Payment;
use App\Payments\MpesaGateway;
use App\Secrets\IntegrationCredentials;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-11: clients paying invoices online by M-Pesa (STK push) and card (Stripe Checkout). */
class OnlinePaymentTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private const WHSEC = 'whsec_test_123';

    private Invoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([SendSmsJob::class]);
        $case = Cases::factory()->create();
        $this->invoice = Invoice::create(['case_id' => $case->id, 'client_id' => $case->client_id, 'invoice_date' => now()->toDateString(),
            'invoice_due_date' => now()->addDays(14)->toDateString(), 'workflow_status' => 'draft']);
        DB::table('invoices')->where('id', $this->invoice->id)->update(['workflow_status' => 'submitted', 'currency' => 'KES', 'subtotal' => 1500.40, 'total_amount' => 1500.40]);
        $this->invoice->refresh();
    }

    private function invoice(string $currency = 'KES', bool $submit = true): Invoice
    {
        $case = Cases::factory()->create();
        $inv = Invoice::create(['case_id' => $case->id, 'client_id' => $case->client_id, 'invoice_date' => now()->toDateString(),
            'invoice_due_date' => now()->addDays(14)->toDateString(), 'workflow_status' => 'draft']);
        DB::table('invoices')->where('id', $inv->id)->update(['currency' => $currency, 'subtotal' => 100, 'total_amount' => 100]);
        if ($submit) {
            DB::table('invoices')->where('id', $inv->id)->update(['workflow_status' => 'submitted']);
        }

        return $inv->fresh();
    }

    private function configure(): void
    {
        app(IntegrationCredentials::class)->set('mpesa', ['consumer_key' => 'ck', 'consumer_secret' => 'cs', 'shortcode' => '174379', 'passkey' => 'pk', 'environment' => 'sandbox'], null);
        app(IntegrationCredentials::class)->set('stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => self::WHSEC], null);
    }

    private function link(): string
    {
        $staff = $this->userWithPermissions([P::CREATE_PAYMENTS, P::LIST_PAYMENTS, P::LIST_INVOICES]);
        $url = $this->actingAs($staff)->postJson("/api/v1/invoices/{$this->invoice->id}/payment-links", ['send_sms' => true])
            ->assertCreated()->assertJsonPath('data.sent', true)->json('data.url');
        Bus::assertDispatched(SendSmsJob::class);
        app('auth')->forgetGuards();

        return substr($url, strrpos($url, '/') + 1);
    }

    private function fakeDaraja(string $checkoutId = 'ws_CO_123'): void
    {
        Http::fake([
            'sandbox.safaricom.co.ke/oauth/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3599]),
            'sandbox.safaricom.co.ke/mpesa/stkpush/*' => Http::response(['ResponseCode' => '0', 'CheckoutRequestID' => $checkoutId, 'MerchantRequestID' => 'm1']),
        ]);
    }

    private function mpesaCallbackBody(string $checkoutId, int $amount, int $code = 0): array
    {
        return ['Body' => ['stkCallback' => ['MerchantRequestID' => 'm1', 'CheckoutRequestID' => $checkoutId, 'ResultCode' => $code, 'ResultDesc' => $code ? 'Request cancelled by user' : 'The service request is processed successfully.',
            'CallbackMetadata' => ['Item' => [['Name' => 'Amount', 'Value' => $amount], ['Name' => 'MpesaReceiptNumber', 'Value' => 'QAB12CD34'], ['Name' => 'PhoneNumber', 'Value' => 254712345678]]]]]];
    }

    /** The callback URL Safaricom was given (it carries the attempt's secret). */
    private function sentCallbackUrl(): string
    {
        $url = null;
        Http::assertSent(function ($req) use (&$url) {
            if (str_contains($req->url(), 'stkpush')) {
                $url = $req['CallBackURL'];
            }

            return true;
        });

        return parse_url($url, PHP_URL_PATH);
    }

    public function test_an_mpesa_payment_is_recorded_once_from_the_callback(): void
    {
        $this->configure();
        $this->fakeDaraja();
        $token = $this->link();

        $this->getJson("/api/v1/pay/{$token}")->assertOk()->assertJsonPath('data.outstanding', 1500.4)->assertJsonPath('data.methods.mpesa', true);
        $attempt = $this->postJson("/api/v1/pay/{$token}/mpesa", ['phone' => '0712 345 678'])->assertOk()->json('data.attempt');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'stkpush') && $r['Amount'] === 1501 && $r['PhoneNumber'] === '254712345678' && $r['PartyB'] === '174379');
        $this->getJson("/api/v1/pay/{$token}/attempts/{$attempt}")->assertJsonPath('data.state', 'pending');

        $cb = $this->sentCallbackUrl();
        $this->postJson($cb, $this->mpesaCallbackBody('ws_CO_123', 1501))->assertOk()->assertJsonPath('ResultCode', 0);
        $this->postJson($cb, $this->mpesaCallbackBody('ws_CO_123', 1501))->assertOk();   // Safaricom retries: harmless

        $this->assertSame(1, Payment::count());
        $p = Payment::first();
        $this->assertEquals(1500.40, (float) $p->amount, 'never more than was outstanding');
        $this->assertSame(['mobile_money', 'QAB12CD34'], [$p->method, $p->reference_number]);
        $this->assertSame('paid', $this->invoice->fresh()->payment_status);
        $this->getJson("/api/v1/pay/{$token}/attempts/{$attempt}")->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.receipt', 'QAB12CD34');
        $this->assertStringContainsString('refund 0.60', DB::table('online_payment_attempts')->value('error'));

        // paid in full: nothing more to pay through the link
        $this->postJson("/api/v1/pay/{$token}/mpesa", ['phone' => '0712345678'])->assertUnprocessable();
    }

    public function test_a_cancelled_or_forged_mpesa_callback_records_nothing(): void
    {
        $this->configure();
        $this->fakeDaraja();
        $token = $this->link();
        $attempt = $this->postJson("/api/v1/pay/{$token}/mpesa", ['phone' => '254712345678'])->json('data.attempt');

        $this->postJson('/api/v1/pay/callback/mpesa/'.str_repeat('x', 40), $this->mpesaCallbackBody('ws_CO_123', 1501))->assertOk();
        $cb = $this->sentCallbackUrl();
        $this->postJson($cb, $this->mpesaCallbackBody('ws_CO_OTHER', 1501))->assertOk();
        $this->assertSame(0, Payment::count(), 'wrong secret or wrong checkout id: ignored');

        $this->postJson($cb, $this->mpesaCallbackBody('ws_CO_123', 0, 1032))->assertOk();
        $this->getJson("/api/v1/pay/{$token}/attempts/{$attempt}")->assertJsonPath('data.state', 'failed')->assertJsonPath('data.error', 'Request cancelled by user');
        $this->assertSame(0, Payment::count());
    }

    public function test_a_card_payment_through_stripe_checkout_and_its_signed_webhook(): void
    {
        $this->configure();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_test_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_test_1'])]);
        $token = $this->link();

        $this->postJson("/api/v1/pay/{$token}/card")->assertOk()->assertJsonPath('data.redirect', 'https://checkout.stripe.com/c/pay/cs_test_1');
        Http::assertSent(fn ($r) => $r['line_items'][0]['price_data']['unit_amount'] === 150040 && $r['line_items'][0]['price_data']['currency'] === 'kes'
            && str_ends_with($r['success_url'], "/pay/{$token}?paid=1"));

        $event = json_encode(['type' => 'checkout.session.completed', 'data' => ['object' => ['id' => 'cs_test_1', 'payment_status' => 'paid', 'amount_total' => 150040, 'payment_intent' => 'pi_1']]]);
        $sign = fn (string $payload, int $t, string $secret = self::WHSEC) => "t={$t},v1=".hash_hmac('sha256', $t.'.'.$payload, $secret);

        $this->call('POST', '/api/v1/pay/callback/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign($event, time(), 'wrong')], $event)->assertStatus(400);
        $this->call('POST', '/api/v1/pay/callback/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign($event, time() - 3600)], $event)->assertStatus(400);
        $this->assertSame(0, Payment::count());

        $this->call('POST', '/api/v1/pay/callback/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign($event, time())], $event)->assertOk();
        $this->call('POST', '/api/v1/pay/callback/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_STRIPE_SIGNATURE' => $sign($event, time())], $event)->assertOk();
        $this->assertSame(1, Payment::count());
        $this->assertSame(['card', 'pi_1'], [Payment::first()->method, Payment::first()->reference_number]);
        $this->assertEquals(1500.40, (float) Payment::first()->amount);
    }

    public function test_links_expire_can_be_revoked_and_only_work_for_issued_invoices(): void
    {
        $this->configure();
        $token = $this->link();
        $this->get("/pay/{$token}")->assertOk()->assertSee('Pay invoice')->assertHeader('Content-Security-Policy');

        DB::table('invoice_payment_links')->update(['expires_at' => now()->subMinute()]);
        $this->getJson("/api/v1/pay/{$token}")->assertUnprocessable();

        $staff = $this->userWithPermissions([P::CREATE_PAYMENTS, P::LIST_PAYMENTS]);
        $fresh = $this->actingAs($staff)->postJson("/api/v1/invoices/{$this->invoice->id}/payment-links")->json('data.url');
        $links = $this->getJson("/api/v1/invoices/{$this->invoice->id}/payment-links")->assertOk()->json('data.links');
        $this->deleteJson("/api/v1/invoices/{$this->invoice->id}/payment-links/{$links[0]['id']}")->assertOk();
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/pay/'.substr($fresh, strrpos($fresh, '/') + 1))->assertUnprocessable();

        $draft = $this->invoice('KES', false);
        $this->actingAs($staff)->postJson("/api/v1/invoices/{$draft->id}/payment-links")->assertUnprocessable();
        $this->getJson('/api/v1/pay/'.str_repeat('A', 40))->assertUnprocessable();
    }

    public function test_methods_that_are_not_set_up_are_refused_and_mpesa_needs_shillings_and_a_kenyan_number(): void
    {
        $token = $this->link();
        $this->getJson("/api/v1/pay/{$token}")->assertJsonPath('data.methods', ['mpesa' => false, 'card' => false]);
        $this->postJson("/api/v1/pay/{$token}/mpesa", ['phone' => '0712345678'])->assertUnprocessable();

        $this->configure();
        $this->postJson("/api/v1/pay/{$token}/mpesa", ['phone' => '12345'])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Safaricom number'));
        $this->invoice = $this->invoice('USD');
        $usd = $this->link();
        $this->postJson("/api/v1/pay/{$usd}/mpesa", ['phone' => '0712345678'])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'Kenya shillings'));
    }

    public function test_a_provider_error_fails_the_attempt_and_says_so(): void
    {
        $this->configure();
        Http::fake(['sandbox.safaricom.co.ke/oauth/*' => Http::response([], 500)]);
        $token = $this->link();
        $this->postJson("/api/v1/pay/{$token}/mpesa", ['phone' => '0712345678'])->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'not available'));
        $this->assertSame('failed', DB::table('online_payment_attempts')->value('state'));
    }

    public function test_staff_need_payment_permissions(): void
    {
        $this->actingAs($this->userWithPermissions([P::LIST_INVOICES]))
            ->postJson("/api/v1/invoices/{$this->invoice->id}/payment-links")->assertForbidden();
        $this->getJson("/api/v1/invoices/{$this->invoice->id}/payment-links")->assertForbidden();
    }

    public function test_mpesa_numbers_are_normalised(): void
    {
        $n = fn ($p) => MpesaGateway::msisdn($p);
        $this->assertSame('254712345678', $n('0712 345 678'));
        $this->assertSame('254712345678', $n('+254-712-345-678'));
        $this->assertSame('254112345678', $n('0112345678'));
        $this->assertNull($n('0812345678'));
        $this->assertNull($n('12345'));
    }
}
