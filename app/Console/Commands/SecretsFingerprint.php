<?php

namespace App\Console\Commands;

use App\Support\SecretFiles;
use Illuminate\Console\Command;

/**
 * CARRY-3 / SEC-15: prove a secret was really replaced, without ever printing it.
 *
 * Prints a short fingerprint (first 12 hex characters of SHA-256) of a secret as this process sees it. Record the fingerprint of the
 * old key before rotating; afterwards `--retired=<old fingerprint>` fails if the running deployment still holds it.
 */
class SecretsFingerprint extends Command
{
    protected $signature = 'secrets:fingerprint {name=TEXTSMS_API_KEY : the environment variable to fingerprint} {--retired= : fingerprint of a key that must no longer be in use}';

    protected $description = 'Show a short, non-reversible fingerprint of a secret (and check a retired key is gone) without printing the secret';

    /** Read a process/.env variable without env() (which is null once config is cached). */
    private function read(string $name): string
    {
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return is_string($v) ? $v : '';
    }

    public function handle(): int
    {
        $name = strtoupper((string) $this->argument('name'));
        $allowed = array_merge(SecretFiles::ALLOWED, array_filter(array_map('trim', explode(',', (string) $this->read('SECRET_FILE_ALLOW')))));
        if (! in_array($name, $allowed, true)) {
            $this->error("{$name} is not a registered secret (see docs/ops/SECRETS.md).");

            return self::FAILURE;
        }
        $value = $this->read($name);
        if ($value === '') {
            $this->error("{$name} is not set in this process.");

            return self::FAILURE;
        }

        $fingerprint = substr(hash('sha256', $value), 0, 12);
        $this->line("{$name} fingerprint: {$fingerprint}");

        $retired = strtolower(trim((string) $this->option('retired')));
        if ($retired !== '' && hash_equals($fingerprint, $retired)) {
            $this->error("{$name} is STILL the retired key. Update the secret store and restart before revoking anything.");

            return self::FAILURE;
        }
        if ($retired !== '') {
            $this->info("{$name} is no longer the retired key ({$retired}).");
        }

        return self::SUCCESS;
    }
}
