<?php

namespace App\Console\Commands;

use App\Portability\Bundle;
use Illuminate\Console\Command;

/** PLAT-9: prove a bundle is intact and readable by this release, without touching any database. */
class PortabilityVerify extends Command
{
    protected $signature = 'portability:verify {input : Bundle directory or .zip}';

    protected $description = 'Check a bundle\'s seal, format version, per-table and per-document checksums';

    public function handle(): int
    {
        try {
            $bundle = Bundle::open($this->argument('input'));
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        try {
            $problems = $bundle->verify();
        } finally {
            $bundle->close();
        }
        if ($problems) {
            foreach ($problems as $p) {
                $this->error($p);
            }

            return self::FAILURE;
        }
        $this->info('Bundle OK: format '.$bundle->manifest['version'].', '.count($bundle->manifest['tables']).' tables, '.($bundle->manifest['files']['count'] ?? 0).' document file(s).');

        return self::SUCCESS;
    }
}
