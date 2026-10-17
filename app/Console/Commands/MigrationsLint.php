<?php

namespace App\Console\Commands;

use App\Support\MigrationLinter;
use Illuminate\Console\Command;

/** DATA-13: CI gate for destructive / rewriting migrations. */
class MigrationsLint extends Command
{
    protected $signature = 'migrations:lint
        {--path=* : Directories to scan (default: database/migrations and database/migrations/control)}
        {--baseline= : Baseline file of grandfathered migrations}
        {--update-baseline : Rewrite the baseline from the current findings (only ever shrinks in review)}';

    protected $description = 'Flag destructive or table-rewriting operations in migrations (expand/contract policy).';

    public function handle(MigrationLinter $linter): int
    {
        $paths = $this->option('path') ?: [database_path('migrations'), database_path('migrations/control')];
        $baselineFile = $this->option('baseline') ?: database_path('migrations/lint-baseline.txt');
        $baseline = is_file($baselineFile)
            ? array_filter(array_map('trim', file($baselineFile, FILE_IGNORE_NEW_LINES)), fn ($l) => $l !== '' && $l[0] !== '#')
            : [];

        $all = [];
        foreach ($paths as $dir) {
            foreach (glob(rtrim($dir, '/').'/*.php') ?: [] as $file) {
                $findings = $linter->lint(file_get_contents($file));
                if ($findings) {
                    $all[basename($file)] = [$file, $findings];
                }
            }
        }

        if ($this->option('update-baseline')) {
            $names = array_keys($all);
            sort($names);
            file_put_contents($baselineFile, "# DATA-13: migrations that predate the lint and are grandfathered.\n# Never add to this list; fix or add an explicit `migration-lint: allow` with a reason.\n".implode("\n", $names)."\n");
            $this->info('Baseline written: '.count($names).' migration(s).');

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($all as $name => [$file, $findings]) {
            if (in_array($name, $baseline, true)) {
                continue;
            }
            $failed++;
            $this->error($name);
            foreach ($findings as $f) {
                $this->line(sprintf('  line %d [%s] %s', $f['line'], $f['rule'], $f['message']));
                $this->line('    '.$f['code']);
            }
        }

        $stale = array_diff($baseline, array_keys($all));
        if ($stale) {
            $this->warn('Baseline lists migrations that no longer have findings (safe to remove): '.implode(', ', $stale));
        }

        if ($failed) {
            $this->newLine();
            $this->error("{$failed} migration(s) violate the expand/contract policy. See docs/MIGRATION_POLICY.md.");

            return self::FAILURE;
        }

        $this->info('Migrations OK ('.count($baseline).' grandfathered).');

        return self::SUCCESS;
    }
}
