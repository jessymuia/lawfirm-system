<?php

namespace App\Console\Commands;

use App\Backup\FirmSnapshots;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Throwable;

/** TEN-14: snapshot firms (database + files + manifest, as a pair) for restore and point-in-time recovery. */
class TenantsSnapshot extends Command
{
    protected $signature = 'tenants:snapshot {slug? : one firm} {--all : every active or suspended firm} {--list : list a firm\'s snapshots}';

    protected $description = "Snapshot firms' databases and files together (docs/ops/FIRM_RESTORE.md)";

    public function handle(FirmSnapshots $snapshots, TenantManager $tenants): int
    {
        if (! $tenants->isMulti()) {
            $this->info('Per-firm snapshots are for multi-tenant mode; a self-hosted install uses backup:run. Nothing to do.');

            return self::SUCCESS;   // the scheduler runs it on every install
        }
        $q = Tenant::query()->whereIn('status', ['active', 'suspended'])->orderBy('slug');
        if ($slug = $this->argument('slug')) {
            $q->where('slug', $slug);
        } elseif (! $this->option('all')) {
            $this->error('Name a firm, or --all.');

            return self::FAILURE;
        }
        $failed = 0;
        foreach ($q->get() as $tenant) {
            if ($this->option('list')) {
                foreach ($snapshots->list($tenant) as $m) {
                    $this->line(sprintf('%s  %s  %s  %d table(s)  %s', $tenant->slug, $m['id'], $m['taken_at'], count($m['tables']), $m['binlog'] ? 'point-in-time ready' : 'snapshot only'));
                }

                continue;
            }
            try {
                $m = $snapshots->take($tenant);
                $this->info(sprintf('%s: snapshot %s (%d rows, %d file(s)%s)', $tenant->slug, $m['id'], array_sum($m['tables']),
                    array_sum(array_column($m['files'], 'files')), $m['binlog'] ? ", binlog {$m['binlog']['file']}:{$m['binlog']['position']}" : ''));
            } catch (Throwable $e) {
                $failed++;
                $this->error("{$tenant->slug}: {$e->getMessage()}");
                logger()->critical("Snapshot of {$tenant->slug} failed: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
