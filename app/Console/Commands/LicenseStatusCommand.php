<?php

namespace App\Console\Commands;

use App\Licensing\LicenseManager;
use Illuminate\Console\Command;

class LicenseStatusCommand extends Command
{
    protected $signature = 'license:status';

    protected $description = 'Show the installed license and its state';

    public function handle(LicenseManager $licenses): int
    {
        $s = $licenses->status()->toArray();
        $this->table(['field', 'value'], collect($s)->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v])->values()->all());

        return in_array($s['state'], ['valid', 'grace'], true) ? self::SUCCESS : self::FAILURE;
    }
}
