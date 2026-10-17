<?php

namespace App\Jobs;

use App\Portability\PortabilityRunner;
use App\Tenancy\Models\Tenant;
use App\Tenancy\PlatformJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * TEN-9: the first step of deletion. When a firm is offboarded its complete data (database rows and documents, the
 * PLAT-9 bundle) is exported once and kept through the retention window, so it can be handed over and nothing is
 * deleted that was never exported. Purge refuses a firm without one unless told otherwise, and deletes it last.
 */
class ExportOffboardedTenant implements PlatformJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $slug) {}

    public function handle(PortabilityRunner $runner): void
    {
        $tenant = Tenant::where('slug', $this->slug)->first();
        if (! $tenant || $tenant->status !== 'offboarded') {
            return;   // resumed meanwhile, or already purged
        }

        $path = self::directory().'/'.$this->slug.'-final-'.now()->format('Ymd-His').'.zip';
        try {
            $r = $runner->exportTenantZip($tenant, $path);
        } catch (\Throwable $e) {
            // recorded, not thrown: the firm stays offboarded, purge waits for a final export, and the console and
            // `tenants:purge-due` show why (`tenants:final-export <slug>` retries)
            @unlink($path);
            $tenant->update(['final_export_error' => mb_substr($e->getMessage(), 0, 4000)]);
            report($e);

            return;
        }

        $previous = $tenant->final_export_path;
        $tenant->update(['final_export_path' => $path, 'final_export_sha256' => $r['sha256'], 'final_export_at' => now(), 'final_export_error' => null]);
        if ($previous && $previous !== $path) {
            @unlink($previous);
        }
    }

    public static function directory(): string
    {
        return rtrim((string) config('portability.directory'), '/').'/offboarded';
    }
}
