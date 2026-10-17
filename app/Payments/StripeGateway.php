<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;

/**
 * FUN-11: card payment through Stripe Checkout with the FIRM's own Stripe account. The payer is redirected to Stripe's
 * hosted page (no card data ever touches this application); the result arrives as a signed webhook.
 */
class StripeGateway
{
    public const TOLERANCE_SECONDS = 300;

    public function __construct(private Credentials $creds) {}

    public function configured(): bool
    {
        return filled($this->creds->resolve('stripe', 'secret_key')) && filled($this->creds->resolve('stripe', 'webhook_secret'));
    }

    /** @return array{id: string, url: string} */
    public function checkout(int $amountMinor, string $currency, string $description, string $successUrl, string $cancelUrl, string $attemptRef): array
    {
        $r = Http::asForm()->withToken((string) $this->creds->resolve('stripe', 'secret_key'))->timeout(20)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'client_reference_id' => $attemptRef,
                'metadata' => ['attempt' => $attemptRef],
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => ['currency' => strtolower($currency), 'unit_amount' => $amountMinor, 'product_data' => ['name' => mb_substr($description, 0, 200)]],
                ]],
            ]);
        if (! $r->successful() || ! $r->json('id') || ! $r->json('url')) {
            throw new PaymentGatewayException('Card payment is not available right now. Please try again shortly.');
        }

        return ['id' => (string) $r->json('id'), 'url' => (string) $r->json('url')];
    }

    /** Stripe-Signature: t=<ts>,v1=<hex hmac of "t.payload">. Constant-time, with a replay window. */
    public function verify(string $payload, ?string $header): bool
    {
        $secret = (string) $this->creds->resolve('stripe', 'webhook_secret');
        if ($secret === '' || ! $header) {
            return false;
        }
        $parts = [];
        foreach (explode(',', $header) as $kv) {
            [$k, $v] = array_pad(explode('=', trim($kv), 2), 2, '');
            $parts[$k][] = $v;
        }
        $t = (int) ($parts['t'][0] ?? 0);
        if ($t === 0 || abs(time() - $t) > self::TOLERANCE_SECONDS) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($parts['v1'] ?? [] as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }
}
