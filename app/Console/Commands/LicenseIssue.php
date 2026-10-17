<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** VENDOR TOOL (not for customer installs): sign a license with the private key. */
class LicenseIssue extends Command
{
    protected $signature = 'license:issue
        {--key= : Path to license-private.key}
        {--licensee= : Customer name}
        {--plan=professional : Plan in config/entitlements.php}
        {--days=365 : Term in days from today}
        {--grace=30 : Grace days after expiry (everything still works, with a warning)}
        {--users= : Override the user limit (integer)}
        {--feature=* : Feature overrides, e.g. sso=1 or trust_accounting=0}
        {--out=license.lic : Output file}';

    protected $description = 'VENDOR: issue a signed license file';

    public function handle(): int
    {
        $keyFile = (string) $this->option('key');
        $secret = is_file($keyFile) ? base64_decode(trim(file_get_contents($keyFile)), true) : false;
        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->error('--key must point to a license-private.key file.');

            return self::FAILURE;
        }
        if (! $this->option('licensee') || ! isset(config('entitlements.plans')[$this->option('plan')])) {
            $this->error('--licensee and a known --plan are required.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $payload = array_filter([
            'license_id' => (string) Str::uuid(),
            'licensee' => $this->option('licensee'),
            'plan' => $this->option('plan'),
            'issued_at' => $now->toIso8601String(),
            'expires_at' => $now->addDays((int) $this->option('days'))->toIso8601String(),
            'grace_days' => (int) $this->option('grace'),
            'features' => $this->features(),
            'limits' => $this->option('users') !== null ? ['users' => (int) $this->option('users')] : null,
        ], fn ($v) => $v !== null && $v !== []);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = base64_encode(sodium_crypto_sign_detached($json, $secret));
        file_put_contents($this->option('out'), json_encode(['payload' => $json, 'signature' => $signature], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info("Issued {$payload['license_id']} for {$payload['licensee']}, expires {$payload['expires_at']} -> {$this->option('out')}");

        return self::SUCCESS;
    }

    private function features(): array
    {
        $out = [];
        foreach ((array) $this->option('feature') as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '1');
            $out[$k] = (bool) (int) $v;
        }

        return $out;
    }
}
