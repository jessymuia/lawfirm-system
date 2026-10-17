<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** VENDOR TOOL (not for customer installs): create the license signing key pair. */
class LicenseKeygen extends Command
{
    protected $signature = 'license:keygen {--out= : Directory to write license-private.key and license-public.key}';

    protected $description = 'VENDOR: generate an Ed25519 key pair for signing licenses';

    public function handle(): int
    {
        $pair = sodium_crypto_sign_keypair();
        $private = base64_encode(sodium_crypto_sign_secretkey($pair));
        $public = base64_encode(sodium_crypto_sign_publickey($pair));

        if ($dir = $this->option('out')) {
            is_dir($dir) || mkdir($dir, 0700, true);
            file_put_contents("$dir/license-private.key", $private);
            chmod("$dir/license-private.key", 0600);
            file_put_contents("$dir/license-public.key", $public);
            $this->info("Wrote $dir/license-private.key (KEEP SECRET, never ship) and $dir/license-public.key");
        } else {
            $this->line("PRIVATE (keep secret): $private");
        }
        $this->line("PUBLIC (put in LICENSE_PUBLIC_KEYS): $public");

        return self::SUCCESS;
    }
}
