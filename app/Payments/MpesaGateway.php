<?php

namespace App\Payments;

use Illuminate\Support\Facades\Http;

/**
 * FUN-11: Safaricom Daraja "Lipa na M-Pesa Online" (STK push) with the FIRM's own paybill/till credentials. The payer
 * gets a prompt on their phone; the result arrives later at the callback URL (which carries a per-attempt secret,
 * since Safaricom does not sign callbacks).
 */
class MpesaGateway
{
    public function __construct(private Credentials $creds) {}

    public function configured(): bool
    {
        $c = $this->creds->all('mpesa');

        return filled($c['consumer_key']) && filled($c['consumer_secret']) && filled($c['shortcode']) && filled($c['passkey']);
    }

    private function base(): string
    {
        return $this->creds->resolve('mpesa', 'environment') === 'production' ? 'https://api.safaricom.co.ke' : 'https://sandbox.safaricom.co.ke';
    }

    /** Kenyan mobile number as 2547XXXXXXXX / 2541XXXXXXXX, or null. */
    public static function msisdn(string $phone): ?string
    {
        $d = preg_replace('/\D/', '', $phone);
        if (preg_match('/^0([17]\d{8})$/', $d, $m)) {
            return '254'.$m[1];
        }
        if (preg_match('/^254([17]\d{8})$/', $d, $m)) {
            return '254'.$m[1];
        }
        if (preg_match('/^([17]\d{8})$/', $d, $m)) {
            return '254'.$m[1];
        }

        return null;
    }

    /** @return string the CheckoutRequestID */
    public function stkPush(string $msisdn, int $amount, string $accountReference, string $description, string $callbackUrl): string
    {
        $c = $this->creds->all('mpesa');
        $token = Http::withBasicAuth((string) $c['consumer_key'], (string) $c['consumer_secret'])->timeout(15)
            ->get($this->base().'/oauth/v1/generate', ['grant_type' => 'client_credentials']);
        if (! $token->successful() || ! $token->json('access_token')) {
            throw new PaymentGatewayException('M-Pesa is not available right now. Please try again in a few minutes.');
        }
        $ts = now('Africa/Nairobi')->format('YmdHis');
        $r = Http::withToken($token->json('access_token'))->timeout(20)->post($this->base().'/mpesa/stkpush/v1/processrequest', [
            'BusinessShortCode' => $c['shortcode'],
            'Password' => base64_encode($c['shortcode'].$c['passkey'].$ts),
            'Timestamp' => $ts,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $msisdn,
            'PartyB' => $c['shortcode'],
            'PhoneNumber' => $msisdn,
            'CallBackURL' => $callbackUrl,
            'AccountReference' => mb_substr($accountReference, 0, 12),
            'TransactionDesc' => mb_substr($description, 0, 13),
        ]);
        if (! $r->successful() || (string) $r->json('ResponseCode') !== '0' || ! $r->json('CheckoutRequestID')) {
            throw new PaymentGatewayException($r->json('errorMessage') ?: ($r->json('ResponseDescription') ?: 'M-Pesa did not accept the request. Check the number and try again.'));
        }

        return (string) $r->json('CheckoutRequestID');
    }

    /**
     * Read Safaricom's callback body.
     *
     * @return array{checkout_request_id: ?string, ok: bool, message: string, amount: ?float, receipt: ?string, phone: ?string}
     */
    public static function parseCallback(array $body): array
    {
        $cb = $body['Body']['stkCallback'] ?? [];
        $meta = [];
        foreach ($cb['CallbackMetadata']['Item'] ?? [] as $item) {
            $meta[$item['Name'] ?? ''] = $item['Value'] ?? null;
        }

        return [
            'checkout_request_id' => $cb['CheckoutRequestID'] ?? null,
            'ok' => (string) ($cb['ResultCode'] ?? '') === '0',
            'message' => (string) ($cb['ResultDesc'] ?? ''),
            'amount' => isset($meta['Amount']) ? (float) $meta['Amount'] : null,
            'receipt' => isset($meta['MpesaReceiptNumber']) ? (string) $meta['MpesaReceiptNumber'] : null,
            'phone' => isset($meta['PhoneNumber']) ? (string) $meta['PhoneNumber'] : null,
        ];
    }
}
