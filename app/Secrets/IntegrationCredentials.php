<?php

namespace App\Secrets;

use App\Tenancy\Keys\FirmCrypt;
use App\Security\SecurityPolicy;
use Illuminate\Support\Facades\DB;

/**
 * SEC-15: per-firm credentials for third-party services. A firm's own value wins; otherwise the deployment's (env/secret file).
 * Values are encrypted at rest, write-only through the API, and never written to the audit trail.
 */
class IntegrationCredentials implements \App\Payments\Credentials
{
    /** provider => [field => config key holding the deployment default] */
    public const PROVIDERS = [
        // the firm's own outgoing mail (invoices, reminders, password resets): sent from its own mailbox and name
        'mail' => ['host' => 'mail.mailers.smtp.host', 'port' => 'mail.mailers.smtp.port', 'username' => 'mail.mailers.smtp.username',
            'password' => 'mail.mailers.smtp.password', 'scheme' => 'mail.mailers.smtp.scheme', 'from_address' => 'mail.from.address', 'from_name' => 'mail.from.name'],
        'textsms' => ['api_key' => 'services.textsms.api_key', 'partner_id' => 'services.textsms.partner_id', 'shortcode' => 'services.textsms.shortcode'],
        // FUN-11: the firm's own collection accounts (its clients pay the firm, not the platform)
        'mpesa' => ['consumer_key' => 'services.mpesa_collect.consumer_key', 'consumer_secret' => 'services.mpesa_collect.consumer_secret',
            'shortcode' => 'services.mpesa_collect.shortcode', 'passkey' => 'services.mpesa_collect.passkey', 'environment' => 'services.mpesa_collect.environment'],
        'stripe' => ['secret_key' => 'services.stripe_collect.secret_key', 'webhook_secret' => 'services.stripe_collect.webhook_secret'],
        // FUN-16: the OAuth apps people connect their calendars through (a firm may use its own)
        'google_calendar' => ['client_id' => 'calendar.google.client_id', 'client_secret' => 'calendar.google.client_secret'],
        'microsoft_calendar' => ['client_id' => 'calendar.microsoft.client_id', 'client_secret' => 'calendar.microsoft.client_secret', 'tenant' => 'calendar.microsoft.tenant'],
    ];

    /** Providers a firm must configure itself when FIRM_OWN_CREDENTIALS is on: the platform's own accounts are never used for them. */
    public const OWN_ONLY = ['textsms', 'mpesa', 'stripe'];

    private function ownOnly(string $provider): bool
    {
        return (bool) config('tenancy.own_credentials') && in_array($provider, self::OWN_ONLY, true);
    }

    public function resolve(string $provider, string $name): ?string
    {
        $own = DB::table('integration_credentials')->where('provider', $provider)->where('name', $name)->value('value');
        if ($own !== null) {
            return FirmCrypt::decryptString($own);
        }
        $key = self::PROVIDERS[$provider][$name] ?? null;

        return ($key && ! $this->ownOnly($provider)) ? config($key) : null;
    }

    /** @return array<string,?string> */
    public function all(string $provider): array
    {
        $out = [];
        foreach (array_keys(self::PROVIDERS[$provider] ?? []) as $name) {
            $out[$name] = $this->resolve($provider, $name);
        }

        return $out;
    }

    /** What the firm may see: which fields are set, and where they come from. Never the values. */
    public function status(string $provider): array
    {
        $own = DB::table('integration_credentials')->where('provider', $provider)->pluck('name')->all();
        $out = [];
        foreach (self::PROVIDERS[$provider] ?? [] as $name => $key) {
            $fallback = ! $this->ownOnly($provider) && (bool) config($key);
            $out[$name] = ['set' => in_array($name, $own, true) || $fallback, 'source' => in_array($name, $own, true) ? 'firm' : ($fallback ? 'deployment' : null)];
        }

        return $out;
    }

    /** The fields without which a provider cannot be used (the rest are optional). */
    public const REQUIRED = [
        'mpesa' => ['consumer_key', 'consumer_secret', 'shortcode', 'passkey'],
        'stripe' => ['secret_key', 'webhook_secret'],
        'textsms' => ['api_key', 'partner_id', 'shortcode'],
        'mail' => ['host', 'from_address'],
    ];

    /**
     * Is the provider ready, and whose account is it? 'firm' = all required fields are the firm's own; 'platform' = the
     * platform's fallback is supplying some of them; null = not ready. Never any value.
     *
     * @return array{ready: bool, source: ?string, missing: list<string>}
     */
    public function readiness(string $provider): array
    {
        $status = $this->status($provider);
        $missing = [];
        $sources = [];
        foreach (self::REQUIRED[$provider] ?? [] as $name) {
            if (empty($status[$name]['set'])) {
                $missing[] = $name;
            } else {
                $sources[] = $status[$name]['source'];
            }
        }

        return ['ready' => $missing === [], 'source' => $missing === [] ? (in_array('deployment', $sources, true) ? 'platform' : 'firm') : null, 'missing' => $missing];
    }

    /** @param array<string,?string> $values  null/'' removes the firm's own value for that field */
    public function set(string $provider, array $values, ?int $actor): void
    {
        $fields = self::PROVIDERS[$provider] ?? throw new \InvalidArgumentException('Unknown provider.');
        $changed = [];
        foreach ($values as $name => $value) {
            if (! array_key_exists($name, $fields)) {
                throw new \InvalidArgumentException("Unknown field {$name}.");
            }
            if ($value === null || $value === '') {
                DB::table('integration_credentials')->where('provider', $provider)->where('name', $name)->delete();
            } else {
                DB::table('integration_credentials')->updateOrInsert(['provider' => $provider, 'name' => $name], [
                    'value' => FirmCrypt::encryptString($value), 'updated_by' => $actor, 'updated_at' => now(), 'created_at' => now(),
                ]);
            }
            $changed[] = $name;
        }
        // field names only: never the values
        SecurityPolicy::audit('integration_credentials_changed', 'integration', 0, [], ['provider' => $provider, 'fields' => $changed]);
    }
}

