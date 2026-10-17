<?php

namespace App\Tenancy;

use Aws\Credentials\CredentialProvider;
use Aws\Sts\StsClient;
use Illuminate\Support\Facades\Storage;

/**
 * TEN-12: storage isolation that holds even if the application is wrong.
 *
 * With TENANCY_STORAGE_ROLE_ARN set, every S3 disk in TENANCY_STORAGE_DISKS stops using the platform's own credentials
 * while a firm is active. It uses short-lived credentials from STS AssumeRole instead, with the session tagged
 * `tenant=<key>`. The role's policy (`php artisan storage:iam-policy`) only allows
 * `tenants/${aws:PrincipalTag/tenant}/*`, so a firm's credentials cannot read or write another firm's objects, whatever
 * key the code asks for. Credentials are fetched lazily (the first time the disk is used) and reused until they expire.
 *
 * The platform context (`default`) keeps the base credentials: health probes, backups and purge work across firms.
 * Without a role ARN, or on non-S3 disks, nothing changes (local disks have no IAM; see docs/ops/STORAGE_ISOLATION.md).
 *
 * TEN-13: a firm using its own KMS key (BYOK) also gets server-side encryption with that key on every object written.
 */
class TenantStorageCredentials
{
    /** @var array<string, array<string, mixed>> disk => its configuration before any firm was applied */
    private array $base = [];

    /** @var array<string, callable> tenant key => memoized credential provider */
    private array $providers = [];

    public function apply(string $key): void
    {
        $role = config('tenancy.storage_role_arn');
        // TEN-13: a firm that brought its own KMS key has its files encrypted with it by S3 (SSE-KMS)
        $kms = app(Keys\FirmKeys::class)->storageKmsKey($key);
        if (! $role && ! $kms && $this->base === []) {
            return;
        }

        foreach ((array) config('tenancy.storage_disks') as $disk) {
            $config = $this->base[$disk] ?? config("filesystems.disks.{$disk}");
            if (! is_array($config) || ($config['driver'] ?? null) !== 's3') {
                continue;
            }
            $this->base[$disk] = $config;

            $scoped = $config;
            if ($key !== 'default' && $role) {
                unset($scoped['key'], $scoped['secret'], $scoped['token']);
                $scoped['credentials'] = $this->provider($key, (string) $role, $config);
            }
            if ($kms) {
                $scoped['options'] = array_merge((array) ($config['options'] ?? []), ['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => $kms]);
            }
            config(["filesystems.disks.{$disk}" => $scoped]);
            Storage::forgetDisk($disk);
        }
    }

    /** @param array<string, mixed> $base */
    private function provider(string $key, string $role, array $base): callable
    {
        return $this->providers[$key] ??= CredentialProvider::memoize(CredentialProvider::assumeRole([
            'client' => $this->sts($base),
            'assume_role_params' => [
                'RoleArn' => $role,
                'RoleSessionName' => 'tenant-'.$key,
                'DurationSeconds' => (int) config('tenancy.storage_session_seconds', 3600),
                'Tags' => [['Key' => 'tenant', 'Value' => $key]],
            ],
        ]));
    }

    /** @param array<string, mixed> $base */
    private function sts(array $base): StsClient
    {
        if (app()->bound(StsClient::class)) {
            return app(StsClient::class);
        }

        $args = ['version' => 'latest', 'region' => $base['region'] ?? 'us-east-1'];
        if (! empty($base['key']) && ! empty($base['secret'])) {
            $args['credentials'] = array_filter(['key' => $base['key'], 'secret' => $base['secret'], 'token' => $base['token'] ?? null]);
        }

        return new StsClient($args);
    }
}
