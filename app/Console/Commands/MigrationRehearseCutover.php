<?php

namespace App\Console\Commands;

use App\Services\Migration\CutoverRehearsal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * CARRY-5: rehearse the legacy cutover on a copy (docs/ops/LEGACY_CUTOVER.md). Run it on staging with a fresh dump of
 * production, and the documents directory copied beside it.
 *
 *   php artisan migration:rehearse-cutover --dump=/backups/prod-2026-10-08.sql.gz --files=/srv/legacy/storage/app
 *   php artisan migration:rehearse-cutover --from=source --files=/srv/legacy/storage/app      (straight from a database)
 */
class MigrationRehearseCutover extends Command
{
    protected $signature = 'migration:rehearse-cutover
        {--dump= : a mysqldump of the legacy database (.sql or .sql.gz)}
        {--from= : copy from this connection instead (default: the `source` connection, DB_SOURCE_*)}
        {--files= : the legacy storage/app directory, to check every document file (default: this install\'s)}
        {--keep : keep the upgraded copy to look at}
        {--accept-unmapped : go on past values missing from the mapping (rehearsal only; the real upgrade refuses them)}
        {--report= : write the report here (default: the backup disk, under cutover-rehearsals/)}';

    protected $description = 'Rehearse the legacy cutover on a copy: restore, preflight, app:upgrade, verify, time (CARRY-5)';

    public function handle(CutoverRehearsal $rehearsal): int
    {
        try {
            $r = $rehearsal->run(['dump' => $this->option('dump'), 'from' => $this->option('from'), 'files' => $this->option('files'),
                'keep' => (bool) $this->option('keep'), 'accept_unmapped' => (bool) $this->option('accept-unmapped')]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['phase', 'seconds'], collect($r['timings'])->map(fn ($s, $p) => [$p, $s])->values()->all());
        $this->line('Rows per table: '.collect($r['after']['rows'] ?? $r['before']['rows'] ?? [])->map(fn ($n, $t) => "{$t} {$n}")->implode(', '));
        if (isset($r['verify']['invoice_states'])) {
            $this->line('Invoice states after the upgrade: '.json_encode($r['verify']['invoice_states']['actual']));
        }
        if ($r['slowest_migrations_seconds'] ?? []) {
            $this->line('Slowest upgrade steps: '.collect($r['slowest_migrations_seconds'])->map(fn ($s, $m) => "{$m} {$s}s")->implode(', '));
        }
        foreach ($r['warnings'] as $w) {
            $this->warn("! {$w}");
        }
        foreach ($r['blocking'] as $b) {
            $this->error("✗ {$b}");
        }
        $json = json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($path = $this->option('report')) {
            file_put_contents($path, $json);
        } else {
            $path = 'cutover-rehearsals/'.now()->format('Y-m-d_His').'.json';
            Storage::disk((string) config('backup.disk'))->put($path, $json);
            $path = "[backup disk] {$path}";
        }
        $this->line("Report: {$path}");
        if ($r['kept']) {
            $this->line("Kept the upgraded copy: database {$r['scratch_database']}");
        }
        if (! $r['ok']) {
            $this->error('The rehearsal found blocking problems: fix them in the legacy data or the mapping, then rehearse again.');

            return self::FAILURE;
        }
        $this->info("Rehearsal passed. Downtime on this data: about {$r['cutover_window_seconds']} s (restore, {$r['migrations_seconds']} s of schema migrations, checks); the backfills then run in the background.");

        return self::SUCCESS;
    }
}
