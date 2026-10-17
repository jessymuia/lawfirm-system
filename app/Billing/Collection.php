<?php

namespace App\Billing;

use App\Payments\MpesaGateway;
use App\Payments\PaymentGatewayException;
use App\Payments\StripeGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * PLAT-4: asking firms for money (the inbound side is Webhooks). With the platform's own accounts:
 *  - Stripe: a Checkout session for what is still owed on an invoice, reached through a stable pay link that can go in
 *    any email (each visit makes a fresh session for the current balance, so it never shows a stale amount);
 *  - M-Pesa: an STK push to a phone for a KES invoice, its result arriving at a callback that names the invoice;
 *  - Daraja C2B URL registration, so paybill payments with the invoice number as account reach Webhooks::mpesaC2B.
 * No card data or PIN ever touches the platform. Results are recorded through the same idempotent payment path.
 */
class Collection
{
    private StripeGateway $stripe;

    private MpesaGateway $mpesa;

    public function __construct(private Webhooks $webhooks)
    {
        $creds = new PlatformCredentials;
        $this->stripe = new StripeGateway($creds);
        $this->mpesa = new MpesaGateway($creds);
    }

    private function db()
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    public function stripeEnabled(): bool
    {
        return filled(config('billing.stripe.secret_key'));
    }

    public function mpesaEnabled(): bool
    {
        $c = (new PlatformCredentials)->all('mpesa');

        return filled($c['consumer_key']) && filled($c['consumer_secret']) && filled($c['shortcode']) && filled($c['passkey']) && filled(config('billing.mpesa.callback_token'));
    }

    /** The stable card-payment link for an invoice (signed, so invoice numbers cannot be walked), or null without Stripe. */
    public function payUrl(string $number): ?string
    {
        return $this->stripeEnabled() ? $this->platformUrl('/api/platform/pay/'.rawurlencode($number).'?sig='.self::signature($number)) : null;
    }

    public static function signature(string $number): string
    {
        return substr(hash_hmac('sha256', 'billing-pay|'.$number, (string) config('app.key')), 0, 32);
    }

    /** @return array{id: string, url: string} a Checkout session for the balance */
    public function checkout(string $number): array
    {
        $inv = $this->payable($number);
        $outstanding = (int) $inv->total - (int) $inv->amount_paid;
        $done = config('billing.stripe.success_url') ?: $this->platformUrl('/status');

        return $this->stripe->checkout($outstanding, $inv->currency, "Subscription invoice {$number}", $done,
            config('billing.stripe.cancel_url') ?: $done, $number);   // client_reference_id = invoice number (Webhooks::stripe)
    }

    /** @return array{checkout_request_id: string, amount: int} the prompt sent to the phone (whole shillings, rounded up) */
    public function stkPush(string $number, string $phone): array
    {
        if (! $this->mpesaEnabled()) {
            throw new BillingException('M-Pesa collection is not configured.');
        }
        $inv = $this->payable($number);
        if ($inv->currency !== 'KES') {
            throw new BillingException('M-Pesa can only pay KES invoices.');
        }
        $msisdn = MpesaGateway::msisdn($phone) ?? throw new BillingException('That is not a Kenyan mobile number.');
        $amount = (int) ceil(((int) $inv->total - (int) $inv->amount_paid) / 100);   // Daraja takes whole shillings; any cents over become credit
        $callback = $this->platformUrl('/api/platform/webhooks/stk/'.config('billing.mpesa.callback_token').'/'.rawurlencode($number));
        try {
            $id = $this->mpesa->stkPush($msisdn, $amount, $number, 'Subscription', $callback);
        } catch (PaymentGatewayException $e) {
            throw new BillingException($e->getMessage());
        }

        return ['checkout_request_id' => $id, 'amount' => $amount];
    }

    /** Safaricom's STK result for an invoice: a payment (idempotent on the receipt) or a recorded failure. */
    public function stkCallback(string $number, array $body): array
    {
        $cb = MpesaGateway::parseCallback($body);
        $event = (string) ($cb['checkout_request_id'] ?? '');
        if ($event === '') {
            return ['outcome' => 'rejected', 'detail' => 'no CheckoutRequestID'];
        }
        if (! $cb['ok'] || ! $cb['receipt'] || $cb['amount'] === null) {
            $this->db()->table('billing_events')->insertOrIgnore(['provider' => 'mpesa', 'event_id' => $event, 'type' => 'stk',
                'outcome' => 'ignored', 'detail' => mb_substr('not paid: '.$cb['message'], 0, 250), 'created_at' => now(), 'updated_at' => now()]);

            return ['outcome' => 'ignored', 'detail' => $cb['message']];
        }

        return $this->webhooks->record('mpesa', $event, 'stk', $number, (int) round($cb['amount'] * 100), 'KES', (string) $cb['receipt']);
    }

    /** Daraja C2B: tell Safaricom where paybill confirmations go. @return array<string, mixed> Safaricom's answer */
    public function registerC2B(): array
    {
        $c = (new PlatformCredentials)->all('mpesa');
        if (! filled($c['consumer_key']) || ! filled($c['consumer_secret']) || ! filled($c['shortcode']) || ! filled(config('billing.mpesa.callback_token'))) {
            throw new BillingException('Set MPESA_CONSUMER_KEY, MPESA_CONSUMER_SECRET, MPESA_SHORTCODE and MPESA_CALLBACK_TOKEN first.');
        }
        $base = $c['environment'] === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
        $token = Http::withBasicAuth((string) $c['consumer_key'], (string) $c['consumer_secret'])->timeout(15)
            ->get($base.'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        if (! $token->successful() || ! $token->json('access_token')) {
            throw new BillingException('Daraja refused the credentials (HTTP '.$token->status().').');
        }
        // not /webhooks/mpesa/: Daraja refuses URLs containing "mpesa" or "safaricom"
        $url = $this->platformUrl('/api/platform/webhooks/c2b/'.config('billing.mpesa.callback_token'));
        $r = Http::withToken((string) $token->json('access_token'))->timeout(20)->post($base.'/mpesa/c2b/v2/registerurl', [
            'ShortCode' => $c['shortcode'],
            'ResponseType' => 'Completed',   // if our validation URL cannot be reached, Safaricom completes the payment anyway
            'ConfirmationURL' => $url,
            'ValidationURL' => $url,
        ]);
        if (! $r->successful() || ! in_array((string) $r->json('ResponseCode'), ['0', '00000000'], true)) {
            throw new BillingException('Daraja did not register the URLs: '.($r->json('errorMessage') ?: $r->json('ResponseDescription') ?: 'HTTP '.$r->status()));
        }

        return (array) $r->json();
    }

    private function payable(string $number): object
    {
        $inv = $this->db()->table('billing_invoices')->where('number', $number)->first() ?? throw new BillingException("No such invoice [{$number}].");
        if ($inv->status === 'void') {
            throw new BillingException('That invoice is void.');
        }
        if ((int) $inv->amount_paid >= (int) $inv->total) {
            throw new BillingException('That invoice is already paid.');
        }

        return $inv;
    }

    public function platformUrl(string $path): string
    {
        $host = (string) config('tenancy.platform_host');
        $root = $host !== '' ? (parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https').'://'.$host : rtrim((string) config('app.url'), '/');

        return $root.$path;
    }
}
