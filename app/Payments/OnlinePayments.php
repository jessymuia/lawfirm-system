<?php

namespace App\Payments;

use App\Actions\Payments\RecordPayment;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FUN-11: online payment of an invoice by the client. A payment link (secret, expiring) opens the invoice's payment
 * page; each try is an attempt; the provider's callback completes it by recording an ordinary Payment, exactly once,
 * against the invoice (receipt number, invoice totals and status follow as for any payment).
 *
 * Only issued (submitted, not void) invoices with something outstanding can be paid. A payment never records more
 * than is outstanding at the moment it lands; anything more is noted on the attempt for a manual refund.
 */
class OnlinePayments
{
    public const LINK_DAYS = 30;

    public function __construct(private MpesaGateway $mpesa, private StripeGateway $stripe) {}

    /** @return string the plain token (shown once, put in the URL) */
    public function createLink(Invoice $invoice, ?int $by, int $days = self::LINK_DAYS): string
    {
        $token = Str::random(40);
        DB::table('invoice_payment_links')->insert([
            'invoice_id' => $invoice->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays($days),
            'created_by' => $by, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $token;
    }

    /** @return array{link: object, invoice: Invoice, outstanding: float} */
    public function open(string $token): array
    {
        $link = DB::table('invoice_payment_links')->where('token_hash', hash('sha256', $token))->first();
        if (! $link || $link->revoked_at || now()->gte($link->expires_at)) {
            throw new PaymentGatewayException('This payment link is not valid or has expired. Ask the firm for a new one.');
        }
        // the payer is not a signed-in user: read the invoice past matter access, by the link's own id only
        $invoice = Invoice::withoutGlobalScopes()->with(['client' => fn ($q) => $q->withoutGlobalScopes()])->whereNull('deleted_at')->find($link->invoice_id);
        if (! $invoice || $invoice->workflow_status !== 'submitted') {
            throw new PaymentGatewayException('This invoice cannot be paid online. Please contact the firm.');
        }

        return ['link' => $link, 'invoice' => $invoice, 'outstanding' => round((float) $invoice->total_amount - (float) $invoice->amount_paid, 2)];
    }

    public function methods(): array
    {
        return ['mpesa' => $this->mpesa->configured(), 'card' => $this->stripe->configured()];
    }

    public function startMpesa(string $token, string $phone, string $callbackBase): object
    {
        ['link' => $link, 'invoice' => $inv, 'outstanding' => $due] = $this->open($token);
        $this->payable($due);
        if (! $this->mpesa->configured()) {
            throw new PaymentGatewayException('M-Pesa payment is not set up for this firm.');
        }
        if (strtoupper((string) $inv->currency) !== 'KES') {
            throw new PaymentGatewayException('M-Pesa can only pay invoices in Kenya shillings.');
        }
        $msisdn = MpesaGateway::msisdn($phone) ?? throw new PaymentGatewayException('Enter a Safaricom number like 0712 345 678.');
        $amount = (int) ceil($due);   // M-Pesa takes whole shillings; the record never exceeds what is outstanding
        $cbToken = Str::random(40);
        $id = $this->attempt($inv, $link, 'mpesa', $amount, 'KES', ['phone' => $msisdn, 'callback_token_hash' => hash('sha256', $cbToken)]);
        try {
            $ref = $this->mpesa->stkPush($msisdn, $amount, (string) ($inv->invoice_number ?: 'INV'.$inv->id), 'Invoice', rtrim($callbackBase, '/').'/'.$cbToken);
        } catch (PaymentGatewayException $e) {
            $this->fail($id, $e->getMessage());
            throw $e;
        }
        DB::table('online_payment_attempts')->where('id', $id)->update(['provider_reference' => $ref, 'updated_at' => now()]);

        return DB::table('online_payment_attempts')->find($id);
    }

    /** @return array{attempt: object, redirect: string} */
    public function startCard(string $token, string $successUrl, string $cancelUrl): array
    {
        ['link' => $link, 'invoice' => $inv, 'outstanding' => $due] = $this->open($token);
        $this->payable($due);
        if (! $this->stripe->configured()) {
            throw new PaymentGatewayException('Card payment is not set up for this firm.');
        }
        $currency = strtoupper((string) ($inv->currency ?: 'KES'));
        $id = $this->attempt($inv, $link, 'stripe', $due, $currency);
        try {
            $s = $this->stripe->checkout((int) round($due * 100), $currency, 'Invoice '.($inv->invoice_number ?: $inv->id), $successUrl, $cancelUrl, 'attempt-'.$id);
        } catch (PaymentGatewayException $e) {
            $this->fail($id, $e->getMessage());
            throw $e;
        }
        DB::table('online_payment_attempts')->where('id', $id)->update(['provider_reference' => $s['id'], 'updated_at' => now()]);

        return ['attempt' => DB::table('online_payment_attempts')->find($id), 'redirect' => $s['url']];
    }

    /** The provider says it was paid. Records the Payment once; a replayed callback changes nothing. */
    public function complete(int $attemptId, float $paid, ?string $receipt, ?string $phone = null): object
    {
        return DB::transaction(function () use ($attemptId, $paid, $receipt, $phone) {
            $a = DB::table('online_payment_attempts')->where('id', $attemptId)->lockForUpdate()->first();
            if (! $a || $a->state !== 'pending') {
                return $a;
            }
            $inv = Invoice::withoutGlobalScopes()->lockForUpdate()->find($a->invoice_id);
            $due = round((float) $inv->total_amount - (float) $inv->amount_paid, 2);
            $note = $paid > $due + 0.009 ? sprintf('Paid %.2f against %.2f outstanding: refund %.2f manually.', $paid, $due, $paid - $due) : null;
            $by = DB::table('invoice_payment_links')->where('id', $a->invoice_payment_link_id)->value('created_by') ?? $inv->created_by;
            $paymentId = app(RecordPayment::class)->handle($inv, [
                'amount' => $paid, 'payment_date' => now()->toDateString(), 'method' => $a->provider === 'mpesa' ? 'mobile_money' : 'card',
                'reference_number' => $receipt, 'notes' => 'Paid online ('.($a->provider === 'mpesa' ? 'M-Pesa' : 'card').')'.($note ? '. '.$note : ''),
            ], $by ? (int) $by : null, capAtBalance: true)?->id;
            DB::table('online_payment_attempts')->where('id', $attemptId)->update([
                'state' => 'succeeded', 'receipt' => $receipt, 'phone' => $phone ?? $a->phone, 'payment_id' => $paymentId,
                'error' => $note, 'updated_at' => now(),
            ]);

            return DB::table('online_payment_attempts')->find($attemptId);
        });
    }

    public function fail(int $attemptId, string $message): void
    {
        DB::table('online_payment_attempts')->where('id', $attemptId)->where('state', 'pending')
            ->update(['state' => 'failed', 'error' => mb_substr($message, 0, 500), 'updated_at' => now()]);
    }

    private function payable(float $due): void
    {
        if ($due <= 0) {
            throw new PaymentGatewayException('This invoice has already been paid in full. Thank you.');
        }
    }

    private function attempt(Invoice $inv, object $link, string $provider, float $amount, string $currency, array $extra = []): int
    {
        return (int) DB::table('online_payment_attempts')->insertGetId([
            'invoice_id' => $inv->id, 'invoice_payment_link_id' => $link->id, 'provider' => $provider, 'amount' => $amount,
            'currency' => $currency, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ] + $extra);
    }
}
