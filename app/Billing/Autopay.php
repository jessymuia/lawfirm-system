<?php

namespace App\Billing;

use App\Tenancy\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * PLAT-4: a firm saves a card once and its subscription invoices are charged automatically.
 *
 *  - Saving: a signed link per subscription opens Stripe Checkout in setup mode for the firm's Stripe customer (created
 *    on first use); the signed webhook then stores the payment method's id and a label ("visa •••• 4242") and turns
 *    automatic payment on. Card numbers never reach the platform.
 *  - Charging: `billing:run` charges every open invoice of such a firm off-session for what is still owed, at most
 *    `billing.autopay.max_attempts` times, `billing.autopay.retry_days` apart, each attempt with its own idempotency key
 *    (a retried request cannot charge twice). A success is recorded at once through the idempotent payment path (the
 *    later payment_intent.succeeded webhook is then a duplicate); a decline is recorded in billing_events and the
 *    normal reminders carry on. A card that needs the holder (3-D Secure) is not retried: the reminder's pay link is
 *    the way to pay.
 *  - Removing: staff (or the firm, through staff) turn it off; the card is detached from the customer.
 */
class Autopay
{
    private const API = 'https://api.stripe.com/v1';

    public function __construct(private Billing $billing, private Collection $collection) {}

    private function db()
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    private function http()
    {
        return Http::asForm()->withToken((string) config('billing.stripe.secret_key'))->timeout(20);
    }

    public function enabled(): bool
    {
        return $this->collection->stripeEnabled();
    }

    public static function signature(int $subscriptionId): string
    {
        return substr(hash_hmac('sha256', 'billing-autopay|'.$subscriptionId, (string) config('app.key')), 0, 32);
    }

    /** The signed link that lets the firm save a card for automatic payment (null without Stripe). */
    public function setupUrl(int $subscriptionId): ?string
    {
        return $this->enabled() ? $this->collection->platformUrl("/api/platform/autopay/{$subscriptionId}?sig=".self::signature($subscriptionId)) : null;
    }

    /** @return string the Checkout (setup mode) URL to send the visitor to */
    public function checkout(int $subscriptionId): string
    {
        $sub = $this->db()->table('subscriptions')->find($subscriptionId) ?? throw new BillingException('No such subscription.');
        if (in_array($sub->status, ['canceled', 'expired'], true)) {
            throw new BillingException('That subscription has ended.');
        }
        $customer = $sub->stripe_customer_id ?: $this->createCustomer($sub);
        $done = config('billing.stripe.success_url') ?: $this->collection->platformUrl('/status');
        $r = $this->http()->post(self::API.'/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $customer,
            'payment_method_types' => ['card'],
            'client_reference_id' => "autopay:{$sub->id}",
            'metadata' => ['subscription' => (string) $sub->id],
            'success_url' => $done,
            'cancel_url' => config('billing.stripe.cancel_url') ?: $done,
        ]);
        if (! $r->successful() || ! $r->json('url')) {
            throw new BillingException('Card setup is not available right now. Please try again shortly.');
        }

        return (string) $r->json('url');
    }

    /** checkout.session.completed in setup mode: store the saved card and turn automatic payment on. */
    public function completeSetup(array $session): array
    {
        $ref = (string) ($session['client_reference_id'] ?? '');
        $subId = str_starts_with($ref, 'autopay:') ? (int) substr($ref, 8) : 0;
        $sub = $subId ? $this->db()->table('subscriptions')->find($subId) : null;
        if (! $sub || (string) ($session['customer'] ?? '') !== (string) $sub->stripe_customer_id) {
            return ['outcome' => 'unmatched', 'detail' => 'no subscription for this setup'];
        }
        $si = $this->http()->get(self::API.'/setup_intents/'.rawurlencode((string) ($session['setup_intent'] ?? '')), ['expand' => ['payment_method']]);
        $pm = (array) $si->json('payment_method');
        if (! $si->successful() || ($si->json('status') !== 'succeeded') || empty($pm['id'])) {
            return ['outcome' => 'rejected', 'detail' => 'setup not completed'];
        }
        $card = (array) ($pm['card'] ?? []);
        $label = trim(($card['brand'] ?? 'card').' •••• '.($card['last4'] ?? '????').(isset($card['exp_month'], $card['exp_year']) ? sprintf(', expires %02d/%d', $card['exp_month'], $card['exp_year']) : ''));
        $old = $sub->stripe_payment_method_id;
        $this->http()->post(self::API.'/customers/'.rawurlencode((string) $sub->stripe_customer_id), ['invoice_settings' => ['default_payment_method' => $pm['id']]]);
        $this->db()->table('subscriptions')->where('id', $sub->id)->update(['stripe_payment_method_id' => $pm['id'], 'card_label' => mb_substr($label, 0, 64), 'autopay' => true, 'updated_at' => now()]);
        if ($old && $old !== $pm['id']) {
            $this->http()->post(self::API.'/payment_methods/'.rawurlencode($old).'/detach');   // the replaced card is not kept
        }

        return ['outcome' => 'applied', 'detail' => "card saved for subscription {$sub->id}"];
    }

    /**
     * Charge every open invoice of the firms paying automatically. @return array{charged: int, failed: int, skipped: int}
     */
    public function chargeDue(?CarbonImmutable $now = null): array
    {
        $now ??= $this->billing->now();
        $r = ['charged' => 0, 'failed' => 0, 'skipped' => 0];
        if (! $this->enabled()) {
            return $r;
        }
        $max = max(1, (int) config('billing.autopay.max_attempts', 3));
        $gap = max(0, (int) config('billing.autopay.retry_days', 3));
        $rows = $this->db()->table('billing_invoices as i')->join('subscriptions as s', 's.id', '=', 'i.subscription_id')
            ->where('i.status', 'open')->where('s.autopay', true)->whereNotNull('s.stripe_payment_method_id')
            ->where('i.issued_at', '<=', $now)->where('i.autopay_attempts', '<', $max)
            ->get(['i.id', 'i.number', 'i.total', 'i.amount_paid', 'i.currency', 'i.autopay_attempts', 'i.autopay_last_at', 's.stripe_customer_id', 's.stripe_payment_method_id']);
        foreach ($rows as $inv) {
            if ($inv->autopay_last_at && CarbonImmutable::parse($inv->autopay_last_at)->addDays($gap)->gt($now)) {
                $r['skipped']++;

                continue;
            }
            $owed = (int) $inv->total - (int) $inv->amount_paid;
            $attempt = (int) $inv->autopay_attempts + 1;
            // claim the attempt first so two overlapping runs cannot both charge
            $claimed = $this->db()->table('billing_invoices')->where('id', $inv->id)->where('autopay_attempts', $inv->autopay_attempts)
                ->update(['autopay_attempts' => $attempt, 'autopay_last_at' => $now]);
            if (! $claimed || $owed <= 0) {
                $r['skipped']++;

                continue;
            }
            $res = $this->http()->withHeaders(['Idempotency-Key' => "autopay-{$inv->number}-{$attempt}"])->post(self::API.'/payment_intents', [
                'amount' => $owed, 'currency' => strtolower($inv->currency), 'customer' => $inv->stripe_customer_id,
                'payment_method' => $inv->stripe_payment_method_id, 'off_session' => 'true', 'confirm' => 'true',
                'description' => "Subscription invoice {$inv->number}", 'metadata' => ['invoice' => $inv->number],
            ]);
            if ($res->successful() && $res->json('status') === 'succeeded') {
                $this->billing->recordPayment($inv->number, (int) $res->json('amount'), strtoupper((string) $res->json('currency')), 'stripe', (string) $res->json('id'), $now);
                $r['charged']++;

                continue;
            }
            $code = (string) ($res->json('error.decline_code') ?: $res->json('error.code') ?: 'http_'.$res->status());
            if ($code === 'authentication_required') {
                // the bank wants the holder: retrying off-session cannot succeed; the reminder's pay link can
                $this->db()->table('billing_invoices')->where('id', $inv->id)->update(['autopay_attempts' => $max]);
            }
            $this->db()->table('billing_events')->insertOrIgnore(['provider' => 'stripe', 'event_id' => "autopay-{$inv->number}-{$attempt}", 'type' => 'autopay',
                'outcome' => 'failed', 'detail' => mb_substr("{$code}: ".($res->json('error.message') ?? ''), 0, 250), 'created_at' => $now, 'updated_at' => $now]);
            $r['failed']++;
        }

        return $r;
    }

    /** Stop automatic payment and remove the saved card from the firm's Stripe customer. */
    public function disable(int $subscriptionId): void
    {
        $sub = $this->db()->table('subscriptions')->find($subscriptionId) ?? throw new BillingException('No such subscription.');
        if ($sub->stripe_payment_method_id && $this->enabled()) {
            $this->http()->post(self::API.'/payment_methods/'.rawurlencode((string) $sub->stripe_payment_method_id).'/detach');
        }
        $this->db()->table('subscriptions')->where('id', $sub->id)->update(['autopay' => false, 'stripe_payment_method_id' => null, 'card_label' => null, 'updated_at' => now()]);
    }

    private function createCustomer(object $sub): string
    {
        $slug = (string) Tenant::find($sub->tenant_id)?->slug;
        $r = $this->http()->withHeaders(['Idempotency-Key' => "customer-{$sub->id}"])->post(self::API.'/customers', array_filter([
            'email' => $sub->billing_email, 'description' => $slug !== '' ? "Firm {$slug}" : null,
            'metadata' => ['subscription' => (string) $sub->id, 'tenant' => $slug],
        ]));
        if (! $r->successful() || ! $r->json('id')) {
            throw new BillingException('Card setup is not available right now. Please try again shortly.');
        }
        $this->db()->table('subscriptions')->where('id', $sub->id)->update(['stripe_customer_id' => $r->json('id'), 'updated_at' => now()]);

        return (string) $r->json('id');
    }
}
