<?php

namespace App\Console\Commands;

use App\Models\FirmExport;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** ENG-21: delete firm-wide exports whose download window has passed. Scheduled hourly. */
class ExportsPrune extends Command
{
    protected $signature = 'exports:prune';

    protected $description = 'Delete expired firm-wide export files';

    public function handle(TenantManager $tenants): int
    {
        $prune = function (): int {
            if (! Schema::hasTable('firm_exports')) {
                return 0;
            }
            $n = 0;
            FirmExport::where('status', 'ready')->where('expires_at', '<=', now())->each(function (FirmExport $e) use (&$n) {
                $e->path && Storage::disk('local')->delete($e->path);
                $e->update(['status' => 'expired', 'path' => null]);
                $n++;
            });
            // a job that died without reaching failed() leaves a request "running" forever
            FirmExport::whereIn('status', ['queued', 'running'])->where('created_at', '<=', now()->subDay())->update(['status' => 'failed', 'error' => 'timed out']);

            return $n;
        };

        $total = 0;
        if (! $tenants->isMulti()) {
            $total = $prune();
        } else {
            foreach (Tenant::whereIn('status', ['active', 'suspended'])->get() as $tenant) {
                $total += $tenants->runIn($tenant, $prune, allowInactive: true);
            }
        }
        $this->info("Deleted {$total} expired export(s).");

        return self::SUCCESS;
    }
}
