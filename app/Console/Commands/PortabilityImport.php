<?php

namespace App\Console\Commands;

use App\Portability\Bundle;
use App\Portability\PortabilityException;
use App\Portability\PortabilityRunner;
use Illuminate\Console\Command;

/** PLAT-9: import into a fresh install or a freshly provisioned firm. Restores ids exactly; never merges. */
class PortabilityImport extends Command
{
    protected $signature = 'portability:import
        {input : Bundle directory or .zip}
        {--tenant= : Firm slug to import into (multi-tenant deployments only)}
        {--replace-bootstrap : Replace the users/roles/catalog rows a first install created}
        {--files-disk= : Store documents on this disk instead of the one they came from}
        {--dry-run : Verify the bundle and the target, change nothing}';

    protected $description = 'Import a firm bundle into an empty installation (all-or-nothing, verified)';

    public function handle(PortabilityRunner $runner): int
    {
        $bundle = null;
        try {
            $bundle = Bundle::open($this->argument('input'));
            $r = $runner->import($this->option('tenant') ?: null, $bundle, [
                'replace_bootstrap' => (bool) $this->option('replace-bootstrap'),
                'dry_run' => (bool) $this->option('dry-run'),
                'files_disk' => $this->option('files-disk') ?: null,
            ]);
        } catch (PortabilityException|\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $bundle?->close();
        }

        $rows = array_sum($r['tables']);
        $this->info(($r['dry_run'] ? 'Dry run OK, would import ' : 'Imported and verified ')."{$rows} rows in ".count($r['tables'])." tables, {$r['files']} document file(s).");
        foreach ($r['notes'] as $n) {
            $this->warn($n);
        }

        return self::SUCCESS;
    }
}
