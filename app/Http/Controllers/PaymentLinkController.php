<?php

namespace App\Http\Controllers;

use App\Jobs\SendSmsJob;
use App\Models\Invoice;
use App\Payments\OnlinePayments;
use App\Payments\PaymentGatewayException;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** FUN-11: staff side of online payment: make (and optionally text) a payment link, see the attempts, revoke links. */
class PaymentLinkController extends Controller
{
    public function __construct(private OnlinePayments $pay) {}

    public function store(\App\Http\Requests\PaymentLink\SendRequest $r, int $invoice): JsonResponse
    {
        $inv = Invoice::with('client')->findOrFail($invoice);   // matter access applies
        if ($inv->workflow_status !== 'submitted') {
            return $this->response(false, 'Only an issued invoice can be paid online. Submit it first.', null, 422);
        }
        $token = $this->pay->createLink($inv, (int) Auth::id(), (int) ($r->validated('days') ?? OnlinePayments::LINK_DAYS));
        $url = $r->getSchemeAndHttpHost().'/pay/'.$token;
        $sent = false;
        if ($r->boolean('send_sms') && $inv->client?->phone_number) {
            $due = number_format((float) $inv->total_amount - (float) $inv->amount_paid, 2);
            SendSmsJob::dispatch((string) $inv->client->phone_number, "Invoice {$inv->invoice_number}: {$inv->currency} {$due} due. Pay securely by M-Pesa or card: {$url}");
            $sent = true;
        }

        return $this->response(true, $sent ? 'Payment link sent by SMS.' : 'Payment link created.', ['url' => $url, 'sent' => $sent, 'methods' => $this->pay->methods()], 201);
    }

    /**
     * Staff-initiated M-Pesa: push an STK prompt to the client's phone for what is outstanding. Uses a fresh payment link
     * internally, so the result lands through the same callback / attempt record as a client paying from a texted link.
     */
    public function mpesa(\App\Http\Requests\PaymentLink\MpesaRequest $r, int $invoice): JsonResponse
    {
        $data = $r->validated();
        $inv = Invoice::findOrFail($invoice);   // matter access applies
        if ($inv->workflow_status !== 'submitted') {
            return $this->response(false, 'Only an issued invoice can be paid. Submit it first.', null, 422);
        }
        try {
            $token = $this->pay->createLink($inv, (int) Auth::id(), 1);
            $a = $this->pay->startMpesa($token, $data['phone'], $r->getSchemeAndHttpHost().'/api/v1/pay/callback/mpesa');
        } catch (PaymentGatewayException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }

        return $this->response(true, 'Prompt sent. The client should enter their M-Pesa PIN on their phone.', ['attempt' => $a->id, 'state' => $a->state], 201);
    }

    /** Staff-initiated card payment: a Stripe Checkout page to open (or hand to the client); the webhook records the payment. */
    public function card(Request $r, int $invoice): JsonResponse
    {
        $inv = Invoice::findOrFail($invoice);
        if ($inv->workflow_status !== 'submitted') {
            return $this->response(false, 'Only an issued invoice can be paid. Submit it first.', null, 422);
        }
        try {
            $token = $this->pay->createLink($inv, (int) Auth::id(), 1);
            $page = $r->getSchemeAndHttpHost().'/pay/'.$token;
            $s = $this->pay->startCard($token, $page.'?paid=1', $page.'?cancelled=1');
        } catch (PaymentGatewayException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }

        return $this->response(true, 'Card checkout ready.', ['attempt' => $s['attempt']->id, 'redirect' => $s['redirect']], 201);
    }

    /** Poll one attempt (an STK push waiting for the client's PIN). */
    public function attempt(int $invoice, int $attempt): JsonResponse
    {
        $inv = Invoice::findOrFail($invoice);
        $a = DB::table('online_payment_attempts')->where('invoice_id', $inv->id)->where('id', $attempt)->first();
        if (! $a) {
            return $this->response(false, 'Unknown payment attempt.', null, 404);
        }

        return $this->response(true, 'success', ['state' => $a->state, 'receipt' => $a->state === 'succeeded' ? $a->receipt : null, 'error' => $a->state === 'failed' ? $a->error : null], 200);
    }

    public function index(int $invoice): JsonResponse
    {
        $inv = Invoice::findOrFail($invoice);

        return $this->response(true, 'success', [
            'methods' => $this->pay->methods(),
            'links' => DB::table('invoice_payment_links')->where('invoice_id', $inv->id)->orderByDesc('id')
                ->get(['id', 'expires_at', 'revoked_at', 'created_at']),
            'attempts' => DB::table('online_payment_attempts')->where('invoice_id', $inv->id)->orderByDesc('id')
                ->get(['id', 'provider', 'amount', 'currency', 'state', 'receipt', 'phone', 'error', 'payment_id', 'created_at']),
        ], 200);
    }

    public function revoke(int $invoice, int $link): JsonResponse
    {
        Invoice::findOrFail($invoice);
        DB::table('invoice_payment_links')->where('invoice_id', $invoice)->where('id', $link)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);

        return $this->response(true, 'Link revoked.', null, 200);
    }
}
