<?php

namespace App\Tenancy\Keys;

use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;

/**
 * TEN-13: per-firm envelope encryption. Each firm has a random 256-bit data key that encrypts what the platform
 * encrypts for that firm: its stored secrets (FirmCrypt: SSO, two-factor, integration, webhook and calendar secrets) and
 * its backups. The data key is only ever stored wrapped (see KeyWrapper): under the platform's master key, or under the
 * firm's own AWS KMS key (BYOK). Destroying the wrapped key on purge makes every copy of that data unreadable,
 * including copies in backups nobody can reach any more (crypto-shredding).
 *
 * Unwrapped keys live in this process's memory only, never in a cache store, the database or a log.
 */
class FirmKeys
{
    /** @var array<int, Encrypter> tenant id => encrypter with its data key */
    private array $encrypters = [];

    /** @var array<string, ?string> slug => the firm's KMS key for its files, or null */
    private array $storageKeys = [];

    /** Firms get a data key when they are created; existing firms with `tenants:keys --enable`. */
    public function enabled(): bool
    {
        return PlatformKeyWrapper::configured() && app(TenantManager::class)->isMulti();
    }

    public function row(Tenant $tenant): ?object
    {
        return DB::connection('control')->table('tenant_keys')->where('tenant_id', $tenant->id)->first();
    }

    /** Give the firm a data key (idempotent). */
    public function create(Tenant $tenant): void
    {
        if ($this->row($tenant) !== null) {
            return;
        }
        $key = random_bytes(32);
        DB::connection('control')->table('tenant_keys')->insert([
            'tenant_id' => $tenant->id, 'provider' => PlatformKeyWrapper::NAME, 'key_ref' => null,
            'wrapped_key' => $this->wrapper(PlatformKeyWrapper::NAME)->wrap($key, $tenant, null),
            'fingerprint' => self::fingerprint($key), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->forget();
    }

    /** The encrypter for the firm's data, or null if the firm has no data key. */
    public function encrypter(Tenant $tenant): ?Encrypter
    {
        if (isset($this->encrypters[$tenant->id])) {
            return $this->encrypters[$tenant->id];
        }
        $row = $this->row($tenant);
        if ($row === null) {
            return null;
        }
        $encrypter = new Encrypter($this->unwrap($tenant, $row), (string) config('app.cipher'));
        // values written before the firm had its own key stay readable until `secrets:rotate` re-encrypts them
        $app = app('encrypter');
        $encrypter->previousKeys([$app->getKey(), ...$app->getPreviousKeys()]);

        return $this->encrypters[$tenant->id] = $encrypter;
    }

    /** BYOK: wrap the firm's data key with its own KMS key instead. The data itself is not re-encrypted. */
    public function useCustomerKey(Tenant $tenant, string $keyArn): array
    {
        if (! preg_match('#^arn:aws[a-z-]*:kms:[a-z0-9-]+:\d{12}:(key|alias)/[A-Za-z0-9/_-]+$#', $keyArn)) {
            throw new \InvalidArgumentException("[{$keyArn}] is not an AWS KMS key ARN (arn:aws:kms:<region>:<account>:key/<id>).");
        }

        return $this->rewrapWith($tenant, AwsKmsKeyWrapper::NAME, $keyArn);
    }

    /** Back from BYOK to the platform's master key. */
    public function usePlatformKey(Tenant $tenant): array
    {
        return $this->rewrapWith($tenant, PlatformKeyWrapper::NAME, null);
    }

    /** After a master-key rotation: rewrap under the current master key if a previous one is still needed. */
    public function rewrap(Tenant $tenant): bool
    {
        $row = $this->live($tenant);
        if ($row->provider !== PlatformKeyWrapper::NAME || (new PlatformKeyWrapper)->current((string) $row->wrapped_key, $tenant)) {
            return false;
        }
        $this->rewrapWith($tenant, PlatformKeyWrapper::NAME, null);

        return true;
    }

    /**
     * Destroy the firm's data key: nothing encrypted with it can be read again, by anyone, from any copy.
     *
     * @return array{fingerprint: string, provider: string, key_ref: ?string}|null null when the firm never had one
     */
    public function destroy(Tenant $tenant): ?array
    {
        $row = $this->row($tenant);
        if ($row === null) {
            return null;
        }
        DB::connection('control')->table('tenant_keys')->where('id', $row->id)
            ->update(['wrapped_key' => null, 'destroyed_at' => $row->destroyed_at ?? now(), 'updated_at' => now()]);
        $this->forget();

        return ['fingerprint' => $row->fingerprint, 'provider' => $row->provider, 'key_ref' => $row->key_ref];
    }

    /** @return array<string, mixed> what an operator may see: never the key */
    public function status(Tenant $tenant): array
    {
        $row = $this->row($tenant);
        if ($row === null) {
            return ['key' => 'none'];
        }
        $status = ['key' => $row->destroyed_at ? 'destroyed' : 'active', 'provider' => $row->provider, 'key_ref' => $row->key_ref,
            'fingerprint' => $row->fingerprint, 'created_at' => $row->created_at, 'rewrapped_at' => $row->rewrapped_at, 'destroyed_at' => $row->destroyed_at];
        if (! $row->destroyed_at) {
            try {
                $this->unwrap($tenant, $row);
                $status['usable'] = true;
            } catch (KeyUnavailable $e) {
                $status['usable'] = false;
                $status['problem'] = $e->getMessage();
            }
        }

        return $status;
    }

    /** TEN-12/13: the KMS key the firm's files are encrypted with on S3 (server-side), when it brought its own. */
    public function storageKmsKey(string $slug): ?string
    {
        if ($slug === 'default' || ! app(TenantManager::class)->isMulti()) {
            return null;
        }
        if (! array_key_exists($slug, $this->storageKeys)) {
            $this->storageKeys[$slug] = DB::connection('control')->table('tenant_keys')->join('tenants', 'tenants.id', '=', 'tenant_keys.tenant_id')
                ->where('tenants.slug', $slug)->where('tenant_keys.provider', AwsKmsKeyWrapper::NAME)->whereNull('tenant_keys.destroyed_at')
                ->value('tenant_keys.key_ref');
        }

        return $this->storageKeys[$slug];
    }

    /** Drop every unwrapped key this process holds. */
    public function forget(): void
    {
        $this->encrypters = [];
        $this->storageKeys = [];
    }

    /** A short public identifier of a data key (to confirm a rewrap kept the same key), from which the key cannot be found. */
    public static function fingerprint(string $key): string
    {
        return substr(hash_hmac('sha256', 'tenant-key-fingerprint', $key), 0, 16);
    }

    private function live(Tenant $tenant): object
    {
        $row = $this->row($tenant);
        if ($row === null) {
            throw new KeyUnavailable("[{$tenant->slug}] has no data key (tenants:keys {$tenant->slug} --enable).");
        }
        if ($row->destroyed_at !== null || $row->wrapped_key === null) {
            throw new KeyUnavailable("The data key of [{$tenant->slug}] was destroyed on ".$row->destroyed_at.'.');
        }

        return $row;
    }

    private function unwrap(Tenant $tenant, object $row): string
    {
        $row = $row->destroyed_at !== null || $row->wrapped_key === null ? $this->live($tenant) : $row;
        $key = $this->wrapper($row->provider)->unwrap((string) $row->wrapped_key, $tenant, $row->key_ref);
        if (! hash_equals($row->fingerprint, self::fingerprint($key))) {
            throw new KeyUnavailable("The data key of [{$tenant->slug}] does not match its fingerprint.");
        }

        return $key;
    }

    /** @return array{fingerprint: string, provider: string, key_ref: ?string} */
    private function rewrapWith(Tenant $tenant, string $provider, ?string $keyRef): array
    {
        $row = $this->live($tenant);
        $key = $this->unwrap($tenant, $row);
        $wrapper = $this->wrapper($provider);
        $wrapped = $wrapper->wrap($key, $tenant, $keyRef);
        if (! hash_equals($key, $wrapper->unwrap($wrapped, $tenant, $keyRef))) {   // prove the new wrapping opens before the old one is replaced
            throw new KeyUnavailable("Rewrapping the data key of [{$tenant->slug}] did not round-trip; nothing changed.");
        }
        DB::connection('control')->table('tenant_keys')->where('id', $row->id)->where('wrapped_key', $row->wrapped_key)
            ->update(['provider' => $provider, 'key_ref' => $keyRef, 'wrapped_key' => $wrapped, 'rewrapped_at' => now(), 'updated_at' => now()]);
        $this->forget();

        return ['fingerprint' => $row->fingerprint, 'provider' => $provider, 'key_ref' => $keyRef];
    }

    private function wrapper(string $provider): KeyWrapper
    {
        return match ($provider) {
            PlatformKeyWrapper::NAME => new PlatformKeyWrapper,
            AwsKmsKeyWrapper::NAME => new AwsKmsKeyWrapper,
            default => throw new KeyUnavailable("Unknown key provider [{$provider}]."),
        };
    }
}
