<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/** PLAT-2: reads the installed license and judges it against a clock that cannot be wound back. */
class LicenseManager
{
    private ?LicenseStatus $memo = null;

    public function __construct(private LicenseVerifier $verifier)
    {
    }

    public function path(): string
    {
        return (string) config('entitlements.license.path');
    }

    public function status(): LicenseStatus
    {
        if ($this->memo) {
            return $this->memo;
        }

        $path = $this->path();
        if (! is_file($path)) {
            return $this->memo = new LicenseStatus(LicenseStatus::MISSING);
        }

        return $this->memo = $this->verifier->verify((string) file_get_contents($path), $this->trustedNow());
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /** Verify a candidate file and, only if it is acceptable, install it. */
    public function install(string $fileContents): LicenseStatus
    {
        $status = $this->verifier->verify($fileContents, $this->trustedNow());

        // Do not replace a working license with one that does not verify or is already dead.
        if ($status->state === LicenseStatus::INVALID) {
            return $status;
        }

        $dir = dirname($this->path());
        is_dir($dir) || mkdir($dir, 0775, true);
        $tmp = $this->path().'.tmp';
        file_put_contents($tmp, $fileContents);
        rename($tmp, $this->path());     // atomic: a crash never leaves a half-written license
        $this->forget();

        return $status;
    }

    /**
     * max(now, latest time ever seen on this install). Winding the server clock back
     * to "revive" an expired license is therefore ignored.
     */
    public function trustedNow(): CarbonImmutable
    {
        $now = CarbonImmutable::now();
        $file = (string) config('entitlements.license.clock_file');
        $seen = is_file($file) ? (int) trim((string) @file_get_contents($file)) : 0;

        if ($seen > $now->getTimestamp()) {
            return CarbonImmutable::createFromTimestamp($seen);
        }
        // Record progress at most hourly to avoid a write per request.
        if ($now->getTimestamp() - $seen > 3600) {
            is_dir(dirname($file)) || @mkdir(dirname($file), 0775, true);
            @file_put_contents($file, (string) $now->getTimestamp());
        }

        return $now;
    }
}
