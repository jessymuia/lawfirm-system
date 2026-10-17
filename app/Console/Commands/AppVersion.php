<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class AppVersion extends Command
{
    protected $signature = 'app:version';

    protected $description = 'Print the installed release version (semver)';

    public function handle(): int
    {
        $this->line(config('app.version'));

        return self::SUCCESS;
    }
}
