<?php

namespace App\Billing;

use App\Payments\Credentials;

/** PLAT-4: the platform's own Stripe and Daraja accounts, from configuration (env or secret files), never a firm's. */
class PlatformCredentials implements Credentials
{
    private const KEYS = [
        'stripe' => ['secret_key' => 'billing.stripe.secret_key', 'webhook_secret' => 'billing.stripe.webhook_secret'],
        'mpesa' => ['consumer_key' => 'billing.mpesa.consumer_key', 'consumer_secret' => 'billing.mpesa.consumer_secret',
            'shortcode' => 'billing.mpesa.shortcode', 'passkey' => 'billing.mpesa.passkey', 'environment' => 'billing.mpesa.environment'],
    ];

    public function resolve(string $provider, string $name): ?string
    {
        $key = self::KEYS[$provider][$name] ?? null;
        $value = $key ? config($key) : null;

        return filled($value) ? (string) $value : null;
    }

    public function all(string $provider): array
    {
        $out = [];
        foreach (array_keys(self::KEYS[$provider] ?? []) as $name) {
            $out[$name] = $this->resolve($provider, $name);
        }

        return $out;
    }
}
