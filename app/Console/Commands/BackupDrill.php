<?php

namespace App\Console\Commands;

use App\Backup\InstallRestoreDrill;
use Illuminate\Console\Command;

/** CARRY-7: restore the newest (or a given) backup:run backup beside the live install, check it, time it, report it. */
class BackupDrill extends Command
{
    protected $signature = 'backup:drill {--backup= : the timestamp of the backup to restore (Y-m-d_His), default the newest} {--keep : keep the scratch database and files to look at}';

    protected $description = 'Restore drill: the latest database + files backup restored to a scratch database and directory, verified and timed';

    public function handle(InstallRestoreDrill $drill): int
    {
        try {
            $r = $drill->run($this->option('backup') ?: null, (bool) $this->option('keep'));
        } catch (\Throwable $e) {
            logger()->critical('Restore drill (backup:drill) could not run: '.$e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Backup: {$r['backup']}".($r['files_backup'] ? " + {$r['files_backup']}" : ' (no files archive)'));
        $this->table(['phase', 'seconds'], collect($r['timings'])->map(fn ($s, $k) => [$k, $s])->values()->all());
        $this->table(['check', 'value'], collect($r['checks'])->map(fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'yes' : 'no') : $v])->values()->all());
        if ($r['scratch']['kept']) {
            $this->line("Kept: database {$r['scratch']['database']}, files {$r['scratch']['directory']}");
        }
        if (! $r['ok']) {
            foreach ($r['problems'] as $p) {
                $this->error($p);
            }
            logger()->critical('Restore drill (backup:drill) FAILED: '.implode('; ', $r['problems']));

            return self::FAILURE;
        }
        $this->info("Restore drill passed in {$r['timings']['total']} s.");
        logger()->info('Restore drill passed', ['backup' => $r['backup'], 'timings' => $r['timings'], 'checks' => $r['checks']]);

        return self::SUCCESS;
    }
}
