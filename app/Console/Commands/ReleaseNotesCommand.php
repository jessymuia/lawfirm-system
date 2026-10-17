<?php

namespace App\Console\Commands;

use App\Releases\ReleaseNotes;
use App\Support\ReleaseLog;
use Illuminate\Console\Command;

/** PLAT-10: what changes when moving between two versions, and which upgrade guides to read first. */
class ReleaseNotesCommand extends Command
{
    protected $signature = 'release:notes {from? : version you run (default: the recorded release)} {to? : target version (default: this code)}
        {--unreleased : include the [Unreleased] section} {--json}';

    protected $description = 'Show the changelog and upgrade guides between two versions';

    public function handle(): int
    {
        $notes = ReleaseNotes::fromApp();
        $from = $this->argument('from') ?? ReleaseLog::current();
        $to = $this->argument('to') ?? (string) config('app.version');
        if ($from === null) {
            $this->line('No installed release is recorded yet (fresh install): there is nothing to upgrade from. Pass a version to compare.');

            return self::SUCCESS;
        }
        $b = $notes->between($from, $to, (bool) $this->option('unreleased'));
        if ($this->option('json')) {
            $this->line(json_encode($b, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        if ($b['boundary'] === 'downgrade') {
            $this->warn("{$to} is older than {$from}: this is a downgrade. Read docs/ops/UPGRADE_ROLLBACK.md first; the schema may be newer than the code.");

            return self::SUCCESS;
        }
        $this->info("{$from} -> {$to}: {$b['boundary']} upgrade, ".count($b['releases']).' release(s)');
        foreach ($b['breaking'] as $x) {
            $this->warn("BREAKING [{$x['version']}] {$x['entry']}");
        }
        foreach (array_reverse($b['releases']) as $r) {
            $this->line('');
            $this->line("## {$r['version']}".($r['date'] ? " ({$r['date']})" : ''));
            $r['summary'] !== '' && $this->line($r['summary']);
            foreach ($r['sections'] as $name => $items) {
                $this->line("  {$name}:");
                foreach ($items as $i) {
                    $this->line('   - '.$i);
                }
            }
        }
        $this->line('');
        foreach ($b['guides'] as $v => $p) {
            $this->info("Upgrade guide {$v}: ".str_replace(base_path().'/', '', $p));
        }
        foreach ($b['missing_guides'] as $v) {
            $this->warn("No upgrade guide was written for {$v}, although it needs one. Treat it with care.");
        }

        return self::SUCCESS;
    }
}
