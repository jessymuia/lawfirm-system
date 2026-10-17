<?php

/**
 * PLAT-4: subscription billing. Control-plane only (SaaS). All money is integer MINOR units (cents) in the subscription's currency.
 *
 * !! The prices below are PLACEHOLDERS to make the system run and be testable. Replace them with your real price list. !!
 */
return [
    'currencies' => ['KES', 'USD'],

    // Monthly base price per plan, minor units. Plans absent here (unlicensed, self_hosted) cannot be subscribed to.
    'plans' => [
        'trial' => ['KES' => 0, 'USD' => 0],
        'starter' => ['KES' => 500000, 'USD' => 3900],          // placeholder
        'professional' => ['KES' => 2500000, 'USD' => 19900],   // placeholder
        'enterprise' => ['KES' => 9000000, 'USD' => 69900],     // placeholder
    ],

    // Metered dimensions = ENG-24 metrics, billed above the plan's included limit (config/entitlements.php).
    //   users: peak `users` gauge in the period; storage: last `storage_bytes` gauge in the period, rounded up to whole GB.
    'overage' => [
        'users' => ['KES' => 80000, 'USD' => 600],               // per extra user per period (placeholder)
        'storage_gb' => ['KES' => 20000, 'USD' => 150],          // per extra GB per period (placeholder)
    ],

    // Tax, in basis points (1600 = 16%). Left at 0: set it from your own tax advice.
    'tax_rate_bp' => (int) env('BILLING_TAX_RATE_BP', 0),

    // PLAT-13: self-service trials. A trial firm is on the `trial` plan; at the end it becomes read-only (everything stays
    // readable and exportable) until someone chooses a paid plan. Firms whose trial lapsed this long ago are offboarded
    // (the normal retention -> final export -> purge sequence, TEN-9). 0 = never offboard automatically.
    'trial' => [
        'days' => (int) env('BILLING_TRIAL_DAYS', 14),
        'currency' => env('BILLING_TRIAL_CURRENCY', 'KES'),
        'warn_days' => 3,
        'offboard_after_days' => (int) env('BILLING_TRIAL_OFFBOARD_AFTER_DAYS', 60),
    ],

    // PLAT-4: firms that saved a card are charged automatically by billing:run (docs/BILLING.md)
    'autopay' => [
        'max_attempts' => (int) env('BILLING_AUTOPAY_MAX_ATTEMPTS', 3),
        'retry_days' => (int) env('BILLING_AUTOPAY_RETRY_DAYS', 3),
    ],

    'payment_terms_days' => (int) env('BILLING_PAYMENT_TERMS_DAYS', 14),

    // Dunning: reminders N days after the due date; optional final action.
    'dunning' => [
        'reminder_days' => [3, 7, 14],
        // Suspend the firm (read access is restored the moment it pays; data is never touched) this many days after the due
        // date. 0 = never suspend automatically. Default: 14.
        'suspend_after_days' => (int) env('BILLING_SUSPEND_AFTER_DAYS', 14),
    ],

    'stripe' => [
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance_seconds' => 300,
        // outbound: Checkout sessions for subscription invoices (the platform's own Stripe account, not a firm's)
        'secret_key' => env('STRIPE_SECRET_KEY'),
        // where Stripe sends the payer afterwards (default: the platform status page)
        'success_url' => env('BILLING_PAY_SUCCESS_URL'),
        'cancel_url' => env('BILLING_PAY_CANCEL_URL'),
    ],

    // M-Pesa C2B (paybill) confirmation: the customer pays the paybill with the INVOICE NUMBER as the account reference.
    // Safaricom does not sign callbacks, so the URL carries a secret token: /api/platform/webhooks/mpesa/{token}.
    'mpesa' => [
        'callback_token' => env('MPESA_CALLBACK_TOKEN'),
        'shortcode' => env('MPESA_SHORTCODE'),
        // outbound (Daraja): STK push to a firm's phone and C2B URL registration (`billing:mpesa-register`)
        'consumer_key' => env('MPESA_CONSUMER_KEY'),
        'consumer_secret' => env('MPESA_CONSUMER_SECRET'),
        'passkey' => env('MPESA_PASSKEY'),
        'environment' => env('MPESA_ENVIRONMENT', 'sandbox'),   // sandbox | production
    ],
];
