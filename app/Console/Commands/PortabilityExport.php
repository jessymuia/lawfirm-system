<?php

namespace App\Console\Commands;

use App\Portability\Bundle;
use App\Portability\Exporter;
use App\Portability\PortabilityException;
use App\Portability\PortabilityRunner;
use Illuminate\Console\Command;

/** PLAT-9: eject. One versioned, checksummed bundle of a firm's data, documents and audit trail. */
class PortabilityExport extends Command
{
    protected $signature = 'portability:export
        {--tenant= : Firm slug (multi-tenant deployments only)}
        {--output= : Directory (or .zip path with --zip) to write; default storage/app/portability/<name>-<time>}
        {--zip : Write a single .zip instead of a directory}
        {--no-files : Leave the document files out (data and audit trail only)}';

    protected $description = 'Export a firm (data, documents, audit trail) as a versioned, checksummed bundle';

    public function handle(PortabilityRunner $runner): int
    {
        $slug = $this->option('tenant') ?: null;
        $name = ($slug ?? 'firm').'-'.now()->format('Ymd-His');
        $out = $this->option('output') ?: rtrim((string) config('portability.directory'), '/').'/'.$name.($this->option('zip') ? '.zip' : '');

        try {
            if ($this->option('zip')) {
                $r = $runner->exportZip($slug, $out, ! $this->option('no-files'));
                $manifest = $r['manifest'];
                $this->line("sha256 {$r['sha256']}");
            } else {
                $manifest = $runner->within($slug, fn () => (new Exporter)->export($out, ! $this->option('no-files')));
            }
        } catch (PortabilityException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $rows = array_sum(array_column($manifest['tables'], 'rows'));
        $this->info("Exported {$rows} rows in ".count($manifest['tables'])." tables, {$manifest['files']['count']} document file(s) to {$out}");
        if (! empty($manifest['files']['missing'])) {
            $this->warn("{$manifest['files']['missing']} document file(s) were missing in storage and are listed as missing in the bundle.");
        }
        $this->warn('The bundle contains password hashes and privileged client material. Store and transfer it like the database itself.');

        return self::SUCCESS;
    }
}
