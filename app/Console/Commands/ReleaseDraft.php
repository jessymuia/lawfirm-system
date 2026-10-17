<?php

namespace App\Console\Commands;

use App\Releases\ConventionalCommits;
use Illuminate\Console\Command;

/** ENG-18 / PLAT-10: draft changelog entries from conventional-commit subjects, e.g. `git log --format=%s v1.0.0..HEAD | php artisan release:draft`. */
class ReleaseDraft extends Command
{
    protected $signature = 'release:draft {file? : file of commit subjects (default: standard input)} {--strict : fail when a subject is not a conventional commit (the CI check)}';

    protected $description = 'Draft changelog entries and a suggested version bump from conventional commits';

    public function handle(ConventionalCommits $cc): int
    {
        $file = $this->argument('file');
        $log = $file ? (is_file($file) ? (string) file_get_contents($file) : null) : stream_get_contents(STDIN);
        if ($log === null) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }
        $d = $cc->draft($log);
        $this->line($cc->markdown($d));
        $this->info('Suggested bump: '.$d['bump'].($d['skipped'] ? " ({$d['skipped']} non-customer-facing commit(s) skipped)" : ''));
        foreach ($d['unparsed'] as $u) {
            $this->option('strict') ? $this->error("Not a conventional commit: {$u}") : $this->warn("Not a conventional commit, review by hand: {$u}");
        }
        if ($this->option('strict') && $d['unparsed'] !== []) {
            $this->line('Use type(scope): summary, with type one of feat, fix, perf, refactor, revert, docs, test, chore, ci, build, style (`!` or a BREAKING CHANGE: footer for breaking changes). See CONTRIBUTING.md.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
