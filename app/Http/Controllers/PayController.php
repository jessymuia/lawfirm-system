<?php

namespace App\Http\Controllers;

use App\Branding\Branding;
use App\Http\Requests\Pay\MpesaRequest;
use App\Payments\MpesaGateway;
use App\Payments\OnlinePayments;
use App\Payments\PaymentGatewayException;
use App\Payments\StripeGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * FUN-11: the client-facing side of online invoice payment, on the firm's own host. Nothing here needs a login: the
 * payment link's secret token is the authority, and it reveals only what the client already received (the invoice).
 * Provider callbacks are authenticated by the per-attempt URL secret (M-Pesa) or the webhook signature (Stripe).
 */
class PayController extends Controller
{
    public function __construct(private OnlinePayments $pay) {}

    public function page(string $token)
    {
        $nonce = bin2hex(random_bytes(16));

        return response()->view('pay.page', ['nonce' => $nonce, 'token' => $token])->withHeaders([
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]);
    }

    public function show(string $token): JsonResponse
    {
        return $this->answer(function () use ($token) {
            ['invoice' => $inv, 'outstanding' => $due] = $this->pay->open($token);

            return [
                'invoice_number' => $inv->invoice_number, 'client' => $inv->client?->name, 'currency' => $inv->currency ?: 'KES',
                'total' => (float) $inv->total_amount, 'paid' => (float) $inv->amount_paid, 'outstanding' => $due,
                'due_date' => $inv->invoice_due_date, 'firm' => Branding::get()['name'], 'methods' => $this->pay->methods(),
            ];
        });
    }

    public function mpesa(MpesaRequest $r, string $token): JsonResponse
    {
        return $this->answer(function () use ($r, $token) {
            $a = $this->pay->startMpesa($token, $r->validated('phone'), $r->getSchemeAndHttpHost().'/api/v1/pay/callback/mpesa');

            return ['attempt' => $a->id, 'state' => $a->state, 'message' => 'Check your phone and enter your M-Pesa PIN to pay.'];
        });
    }

    public function card(Request $r, string $token): JsonResponse
    {
        return $this->answer(function () use ($r, $token) {
            $page = $r->getSchemeAndHttpHost().'/pay/'.$token;
            $s = $this->pay->startCard($token, $page.'?paid=1', $page.'?cancelled=1');

            return ['attempt' => $s['attempt']->id, 'redirect' => $s['redirect']];
        });
    }

    /** The payment page polls this after an STK push. Only attempts made through this same link. */
    public function status(string $token, int $attempt): JsonResponse
    {
        return $this->answer(function () use ($token, $attempt) {
            ['link' => $link, 'outstanding' => $due] = $this->pay->open($token);
            $a = DB::table('online_payment_attempts')->where('id', $attempt)->where('invoice_payment_link_id', $link->id)->first()
                ?? throw new PaymentGatewayException('Unknown payment.');

            return ['state' => $a->state, 'receipt' => $a->state === 'succeeded' ? $a->receipt : null,
                'error' => $a->state === 'failed' ? $a->error : null, 'outstanding' => $due];
        });
    }

    /** Safaricom posts the STK result here; the path carries the attempt's secret. Always answers 200 so it is not retried forever. */
    public function mpesaCallback(Request $r, string $secret): JsonResponse
    {
        $a = DB::table('online_payment_attempts')->where('provider', 'mpesa')->where('callback_token_hash', hash('sha256', $secret))->first();
        $cb = MpesaGateway::parseCallback($r->json()->all());
        if (! $a || $cb['checkout_request_id'] !== $a->provider_reference) {
            Log::warning('pay.mpesa callback rejected', ['known' => (bool) $a]);

            return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
        }
        $cb['ok'] ? $this->pay->complete((int) $a->id, (float) $cb['amount'], $cb['receipt'], $cb['phone'])
            : $this->pay->fail((int) $a->id, $cb['message'] ?: 'The payment was not completed.');

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /** Stripe webhook (checkout.session.completed / .expired), signed with the firm's webhook secret. */
    public function stripeCallback(Request $r, StripeGateway $stripe): JsonResponse
    {
        if (! $stripe->verify($r->getContent(), $r->header('Stripe-Signature'))) {
            return response()->json(['message' => 'bad signature'], 400);
        }
        $event = $r->json()->all();
        $s = $event['data']['object'] ?? [];
        $a = DB::table('online_payment_attempts')->where('provider', 'stripe')->where('provider_reference', $s['id'] ?? '')->first();
        if ($a) {
            if (($event['type'] ?? '') === 'checkout.session.completed' && ($s['payment_status'] ?? '') === 'paid') {
                $this->pay->complete((int) $a->id, ((int) ($s['amount_total'] ?? 0)) / 100, $s['payment_intent'] ?? $s['id']);
            } elseif (($event['type'] ?? '') === 'checkout.session.expired') {
                $this->pay->fail((int) $a->id, 'The card payment was not completed.');
            }
        }

        return response()->json(['received' => true]);
    }

    private function answer(\Closure $f): JsonResponse
    {
        try {
            return $this->response(true, 'success', $f(), 200);
        } catch (PaymentGatewayException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }
    }
}
