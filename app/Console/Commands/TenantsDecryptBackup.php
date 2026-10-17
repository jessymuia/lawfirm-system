<?php

namespace App\Console\Commands;

use App\Tenancy\Keys\BackupCipher;
use App\Tenancy\Keys\FirmKeys;
use App\Tenancy\Models\Tenant;
use Illuminate\Console\Command;
use Throwable;

/**
 * TEN-13: turn a firm's encrypted backup (`*.gz.enc`, from `backup:run --tenant=`) back into the `.gz` a restore uses.
 * Works only while the firm's data key exists: after the firm is purged, its backups cannot be decrypted by anyone.
 */
class TenantsDecryptBackup extends Command
{
    protected $signature = 'tenants:decrypt-backup {slug} {file : the .enc backup file, copied to this machine} {--out= : where to write it (default: the same name without .enc)}';

    protected $description = "Decrypt one of a firm's backups with its data key, for a restore";

    public function handle(FirmKeys $keys): int
    {
        $tenant = Tenant::where('slug', $this->argument('slug'))->first();
        $file = (string) $this->argument('file');
        $out = (string) ($this->option('out') ?: preg_replace('/\.enc$/', '', $file));
        if (! $tenant) {
            $this->error('Unknown tenant.');

            return self::FAILURE;
        }
        if ($out === $file || ! is_file($file)) {
            $this->error('Give an existing .enc file (and --out if it is not named *.enc).');

            return self::FAILURE;
        }
        try {
            $encrypter = $keys->encrypter($tenant);
            if ($encrypter === null) {
                $this->error("[{$tenant->slug}] has no data key, so its backups are not encrypted.");

                return self::FAILURE;
            }
            BackupCipher::decryptFile($file, $out, $encrypter->getKey());
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Decrypted to {$out}.");

        return self::SUCCESS;
    }
}
