<?php

namespace App\Console\Commands;

use App\Licensing\LicenseManager;
use App\Licensing\LicenseStatus;
use Illuminate\Console\Command;

/** Customer tool: verify and install a license file (works offline). */
class LicenseInstall extends Command
{
    protected $signature = 'license:install {file : Path to the .lic file}';

    protected $description = 'Verify and install a license file';

    public function handle(LicenseManager $licenses): int
    {
        if (! is_file($this->argument('file'))) {
            $this->error('File not found.');

            return self::FAILURE;
        }

        $status = $licenses->install(file_get_contents($this->argument('file')));
        if ($status->state === LicenseStatus::INVALID) {
            $this->error('Not installed: '.$status->notice());

            return self::FAILURE;
        }

        $this->info("Installed. State: {$status->state}; expires {$status->expiresAt?->toDateString()}.");
        if ($status->notice()) {
            $this->warn($status->notice());
        }

        return self::SUCCESS;
    }
}
