<?php

namespace App\Console\Commands;

use App\Releases\ReleaseNotes;
use Illuminate\Console\Command;

/** PLAT-10: CI/release gate: changelog well-formed, VERSION in step, every breaking or minor/major release has a complete upgrade guide. */
class ReleaseCheck extends Command
{
    protected $signature = 'release:check';

    protected $description = 'Validate CHANGELOG.md, VERSION and docs/upgrade guides';

    public function handle(): int
    {
        $problems = ReleaseNotes::fromApp()->check();
        foreach ($problems as $p) {
            $this->error($p);
        }
        $problems === [] && $this->info('Changelog, version and upgrade guides are consistent.');

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
