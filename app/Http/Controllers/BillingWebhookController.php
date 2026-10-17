<?php

namespace App\Http\Controllers;

use App\Billing\Webhooks;
use Illuminate\Http\Request;

/** PLAT-4: provider callbacks (no session, no bearer: authenticity comes from the signature / secret URL). */
class BillingWebhookController extends Controller
{
    public function stripe(Request $r, Webhooks $w)
    {
        $secret = (string) config('billing.stripe.webhook_secret');
        if (! Webhooks::stripeSignatureValid($r->getContent(), (string) $r->header('Stripe-Signature'), $secret, (int) config('billing.stripe.tolerance_seconds'))) {
            return response()->json(['message' => 'invalid signature'], 400);
        }
        $event = json_decode($r->getContent(), true);
        if (! is_array($event)) {
            return response()->json(['message' => 'bad payload'], 400);
        }

        return response()->json($w->stripe($event));
    }

    public function mpesa(Request $r, Webhooks $w, string $token)
    {
        $expected = (string) config('billing.mpesa.callback_token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['message' => 'not found'], 404);
        }
        $res = $w->mpesaC2B((array) $r->json()->all());

        // Daraja expects this acknowledgement shape; we always accept so it does not retry (unmatched ones are recorded for reconciliation).
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /** PLAT-4: the result of an STK push for invoice {number}; the secret token authenticates (Safaricom does not sign). */
    public function mpesaStk(Request $r, \App\Billing\Collection $c, string $token, string $number)
    {
        $expected = (string) config('billing.mpesa.callback_token');
        if ($expected === '' || ! hash_equals($expected, $token)) {
            return response()->json(['message' => 'not found'], 404);
        }
        $c->stkCallback($number, (array) $r->json()->all());

        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }

    /**
     * PLAT-4: the pay link in billing emails. Signed per invoice; each visit opens a fresh Stripe Checkout for what is
     * still owed, so an old email never charges a stale amount.
     */
    public function pay(Request $r, \App\Billing\Collection $c, string $number)
    {
        if (! hash_equals(\App\Billing\Collection::signature($number), (string) $r->query('sig')) || ! $c->stripeEnabled()) {
            return response('Not found.', 404);
        }
        try {
            return redirect()->away($c->checkout($number)['url'], 303);
        } catch (\App\Billing\BillingException $e) {
            return response(e($e->getMessage()).' Nothing is owed on it.', 200)->header('Content-Type', 'text/plain; charset=utf-8');
        } catch (\App\Payments\PaymentGatewayException $e) {
            return response('Card payment is not available right now. Please try again shortly.', 503)->header('Content-Type', 'text/plain; charset=utf-8');
        }
    }

    /** PLAT-4: the signed link a firm follows to save a card for automatic payment (Stripe Checkout, setup mode). */
    public function autopay(Request $r, \App\Billing\Autopay $autopay, int $subscription)
    {
        if (! hash_equals(\App\Billing\Autopay::signature($subscription), (string) $r->query('sig')) || ! $autopay->enabled()) {
            return response('Not found.', 404);
        }
        try {
            return redirect()->away($autopay->checkout($subscription), 303);
        } catch (\App\Billing\BillingException $e) {
            return response($e->getMessage(), 422)->header('Content-Type', 'text/plain; charset=utf-8');
        }
    }
}
