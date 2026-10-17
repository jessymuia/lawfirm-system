<?php

namespace App\Tenancy\Keys;

use App\Tenancy\Models\Tenant;
use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;

/**
 * TEN-13 BYOK: the data key is encrypted by the firm's own AWS KMS key (customer-managed key, in its own account,
 * with a key policy or grant that lets the platform's role Encrypt and Decrypt). The encryption context binds it to
 * the firm. The firm stays in control: disabling the key, revoking the grant or scheduling deletion makes its data
 * key, and so everything encrypted with it, unreadable to the platform.
 */
class AwsKmsKeyWrapper implements KeyWrapper
{
    public const NAME = 'aws-kms';

    public function name(): string
    {
        return self::NAME;
    }

    public function wrap(string $dataKey, Tenant $tenant, ?string $keyRef): string
    {
        try {
            $result = $this->client($keyRef)->encrypt(['KeyId' => $keyRef, 'Plaintext' => $dataKey, 'EncryptionContext' => self::context($tenant)]);
        } catch (AwsException $e) {
            throw new KeyUnavailable("The firm's KMS key [{$keyRef}] cannot be used to encrypt: ".($e->getAwsErrorCode() ?? $e->getMessage()), 0, $e);
        }

        return base64_encode((string) $result['CiphertextBlob']);
    }

    public function unwrap(string $wrapped, Tenant $tenant, ?string $keyRef): string
    {
        try {
            $result = $this->client($keyRef)->decrypt(['KeyId' => $keyRef, 'CiphertextBlob' => base64_decode($wrapped), 'EncryptionContext' => self::context($tenant)]);
        } catch (AwsException $e) {
            throw new KeyUnavailable("The firm's KMS key [{$keyRef}] cannot be used to decrypt: ".($e->getAwsErrorCode() ?? $e->getMessage()), 0, $e);
        }

        return (string) $result['Plaintext'];
    }

    /** @return array<string, string> */
    private static function context(Tenant $tenant): array
    {
        return ['tenant' => $tenant->slug];
    }

    private function client(?string $keyRef): KmsClient
    {
        if (app()->bound(KmsClient::class)) {
            return app(KmsClient::class);
        }
        // the region is part of the key's ARN: arn:aws:kms:<region>:<account>:key/<id>
        $region = explode(':', (string) $keyRef)[3] ?? null;

        return new KmsClient(['version' => 'latest', 'region' => $region ?: (string) config('tenancy.encryption.kms_region', 'us-east-1')]);
    }
}
