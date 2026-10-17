<?php

namespace App\Console\Commands;

use App\Documents\DirectUploads;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/** ENG-22: delete what unfinished direct uploads left in the bucket. Scheduled hourly. */
class DocumentsPruneUploads extends Command
{
    protected $signature = 'documents:prune-uploads';

    protected $description = 'Delete files of direct uploads that were never completed';

    public function handle(TenantManager $tenants, DirectUploads $uploads): int
    {
        $prune = fn (): int => Schema::hasTable('document_uploads') ? $uploads->prune() : 0;

        $total = 0;
        if (! $tenants->isMulti()) {
            $total = $prune();
        } else {
            foreach (Tenant::whereIn('status', ['active', 'suspended'])->get() as $tenant) {
                $total += $tenants->runIn($tenant, $prune, allowInactive: true);
            }
        }
        $this->info("Deleted {$total} abandoned upload(s).");

        return self::SUCCESS;
    }
}
