<?php

namespace App\Tenancy\Keys;

use App\Tenancy\Models\Tenant;
use App\Tenancy\PurgeStep;

/** TEN-9 / TEN-13: deleting a firm destroys its data key, which crypto-shreds whatever copies of its encrypted data remain. */
class EncryptionKeyPurgeStep implements PurgeStep
{
    public function name(): string
    {
        return 'encryption_key';
    }

    public function purge(Tenant $tenant): string
    {
        $destroyed = app(FirmKeys::class)->destroy($tenant);
        if ($destroyed === null) {
            return 'none: the firm had no data key (created before TEN-13 was enabled and never given one)';
        }
        $outcome = "destroyed: data key {$destroyed['fingerprint']}; its backups and encrypted secrets can no longer be read";
        if ($destroyed['provider'] === AwsKmsKeyWrapper::NAME) {
            $outcome .= "; the firm's own KMS key {$destroyed['key_ref']} is theirs to disable or schedule for deletion";
        }

        return $outcome;
    }
}
