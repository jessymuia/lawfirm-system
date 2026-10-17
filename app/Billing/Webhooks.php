<?php

namespace App\Billing;

use Illuminate\Support\Facades\DB;

/**
 * PLAT-4: inbound payment notifications. Both handlers are idempotent (provider event ids and payment references are unique)
 * and never raise on an unmatched payment: they record it for reconciliation and acknowledge, so the provider stops retrying.
 *
 * NOT verified against the live providers (no network or credentials here): the Stripe signature scheme and event shape, and the
 * Daraja C2B confirmation fields, are implemented from their public documentation and tested with payloads built to that format.
 */
class Webhooks
{
    public function __construct(private Billing $billing)
    {
    }

    private function db()
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    // ---- Stripe ----------------------------------------------------------------------------------------------------

    /** Stripe-Signature: "t=<unix>,v1=<hex hmac-sha256 of '<t>.<body>'>[,v1=...]" */
    public static function stripeSignatureValid(string $payload, string $header, string $secret, int $tolerance, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if ($t === null || ! ctype_digit($t) || ! $sigs) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $t) > $tolerance) {
            return false;   // stale: blocks replay of a captured request
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{outcome:string,detail:?string} */
    public function stripe(array $event): array
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');
        if ($id === '') {
            return ['outcome' => 'rejected', 'detail' => 'no event id'];
        }
        if (! $this->claim('stripe', $id, $type)) {
            return ['outcome' => 'duplicate', 'detail' => null];
        }
        $o = $event['data']['object'] ?? [];
        // PLAT-4 autopay: a charge made off-session (normally already recorded when it was made: then a duplicate)
        if ($type === 'payment_intent.succeeded' && ! empty($o['metadata']['invoice'])) {
            return $this->apply('stripe', $id, (string) $o['metadata']['invoice'], (int) ($o['amount_received'] ?? 0), strtoupper((string) ($o['currency'] ?? '')), (string) ($o['id'] ?? $id));
        }
        if ($type !== 'checkout.session.completed') {
            return $this->finish('stripe', $id, 'ignored', "event type {$type}");
        }
        if (($o['mode'] ?? '') === 'setup') {   // PLAT-4: a card saved for automatic payment
            $res = app(Autopay::class)->completeSetup($o);

            return $this->finish('stripe', $id, $res['outcome'], $res['detail']);
        }
        if (($o['payment_status'] ?? '') !== 'paid') {
            return $this->finish('stripe', $id, 'ignored', 'not paid');
        }
        $number = (string) ($o['client_reference_id'] ?? '');
        $ref = (string) ($o['payment_intent'] ?? $o['id'] ?? $id);

        return $this->apply('stripe', $id, $number, (int) ($o['amount_total'] ?? 0), strtoupper((string) ($o['currency'] ?? '')), $ref);
    }

    // ---- M-Pesa C2B (paybill) ---------------------------------------------------------------------------------------

    /** Daraja C2B confirmation: TransID, TransAmount (major units), BillRefNumber (= our invoice number). */
    public function mpesaC2B(array $p): array
    {
        $id = (string) ($p['TransID'] ?? '');
        if ($id === '') {
            return ['outcome' => 'rejected', 'detail' => 'no TransID'];
        }
        if (! $this->claim('mpesa', $id, 'c2b')) {
            return ['outcome' => 'duplicate', 'detail' => null];
        }
        $short = (string) config('billing.mpesa.shortcode');
        if ($short !== '' && (string) ($p['BusinessShortCode'] ?? '') !== $short) {
            return $this->finish('mpesa', $id, 'unmatched', 'wrong shortcode');
        }
        // major units with up to 2 decimals -> minor units, without floats
        $major = (string) ($p['TransAmount'] ?? '0');
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $major)) {
            return $this->finish('mpesa', $id, 'rejected', 'bad amount');
        }
        [$whole, $frac] = array_pad(explode('.', $major), 2, '0');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $this->apply('mpesa', $id, trim((string) ($p['BillRefNumber'] ?? '')), $minor, 'KES', $id);
    }

    // ---- shared ----------------------------------------------------------------------------------------------------

    /** A payment notified outside the two webhooks above (the STK callback): same claim / match / record path. */
    public function record(string $provider, string $eventId, string $type, string $number, int $minor, string $currency, string $ref): array
    {
        if (! $this->claim($provider, $eventId, $type)) {
            return ['outcome' => 'duplicate', 'detail' => null];
        }

        return $this->apply($provider, $eventId, $number, $minor, $currency, $ref);
    }

    private function claim(string $provider, string $eventId, string $type): bool
    {
        return $this->db()->table('billing_events')->insertOrIgnore(['provider' => $provider, 'event_id' => $eventId, 'type' => $type, 'outcome' => 'received', 'created_at' => now(), 'updated_at' => now()]) === 1;
    }

    private function finish(string $provider, string $eventId, string $outcome, ?string $detail): array
    {
        $this->db()->table('billing_events')->where('provider', $provider)->where('event_id', $eventId)->update(['outcome' => $outcome, 'detail' => $detail, 'updated_at' => now()]);

        return ['outcome' => $outcome, 'detail' => $detail];
    }

    private function apply(string $provider, string $eventId, string $number, int $minor, string $currency, string $ref): array
    {
        if ($number === '' || ! $this->db()->table('billing_invoices')->where('number', $number)->exists()) {
            return $this->finish($provider, $eventId, 'unmatched', 'no invoice for reference');
        }
        try {
            $res = $this->billing->recordPayment($number, $minor, $currency, $provider, $ref);
        } catch (BillingException $e) {
            return $this->finish($provider, $eventId, 'unmatched', $e->getMessage());
        }

        return $this->finish($provider, $eventId, $res === 'applied' ? 'applied' : 'duplicate', null);
    }
}
