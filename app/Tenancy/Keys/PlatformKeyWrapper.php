<?php

namespace App\Tenancy\Keys;

use App\Tenancy\Models\Tenant;

/**
 * TEN-13: the default wrapping. The data key is sealed with AES-256-GCM under the platform's master key
 * (TENANCY_MASTER_KEY), with the firm's slug as associated data. Master-key rotation: put the new key in
 * TENANCY_MASTER_KEY and the old one in TENANCY_PREVIOUS_MASTER_KEYS, run `tenants:keys --all --rewrap`, then drop the
 * old key. Unwrapping tries the current key first, then the previous ones.
 */
class PlatformKeyWrapper implements KeyWrapper
{
    public const NAME = 'platform';

    public function name(): string
    {
        return self::NAME;
    }

    public static function configured(): bool
    {
        return self::decode((string) config('tenancy.encryption.master_key')) !== null;
    }

    public function wrap(string $dataKey, Tenant $tenant, ?string $keyRef): string
    {
        $kek = self::decode((string) config('tenancy.encryption.master_key'));
        if ($kek === null) {
            throw new KeyUnavailable('TENANCY_MASTER_KEY is not set (32 bytes, base64: prefix), so firm keys cannot be created or rewrapped.');
        }
        $nonce = random_bytes(12);
        $sealed = openssl_encrypt($dataKey, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($tenant));

        return 'v1:'.base64_encode($nonce.$tag.$sealed);
    }

    public function unwrap(string $wrapped, Tenant $tenant, ?string $keyRef): string
    {
        return $this->open($wrapped, $tenant, [(string) config('tenancy.encryption.master_key'), ...(array) config('tenancy.encryption.previous_master_keys')])
            ?? throw new KeyUnavailable("No master key unwraps the data key of [{$tenant->slug}].");
    }

    /** True if the current master key (not a previous one) unwraps it: nothing to rewrap. */
    public function current(string $wrapped, Tenant $tenant): bool
    {
        return $this->open($wrapped, $tenant, [(string) config('tenancy.encryption.master_key')]) !== null;
    }

    /** @param list<string> $masterKeys */
    private function open(string $wrapped, Tenant $tenant, array $masterKeys): ?string
    {
        $raw = str_starts_with($wrapped, 'v1:') ? base64_decode(substr($wrapped, 3), true) : false;
        if ($raw === false || strlen($raw) < 28) {
            return null;
        }
        [$nonce, $tag, $sealed] = [substr($raw, 0, 12), substr($raw, 12, 16), substr($raw, 28)];
        foreach ($masterKeys as $candidate) {
            $kek = self::decode($candidate);
            if ($kek !== null && ($key = openssl_decrypt($sealed, 'aes-256-gcm', $kek, OPENSSL_RAW_DATA, $nonce, $tag, self::aad($tenant))) !== false) {
                return $key;
            }
        }

        return null;
    }

    private static function aad(Tenant $tenant): string
    {
        return 'tenant:'.$tenant->slug;
    }

    private static function decode(string $key): ?string
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : false;

        return $raw !== false && strlen($raw) === 32 ? $raw : null;
    }
}
