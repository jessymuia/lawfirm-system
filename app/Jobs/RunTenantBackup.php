<?php

namespace App\Jobs;

use App\Platform\PlatformAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/** PLAT-7: backup of one tenant database, requested by platform staff. Outcome goes to the platform audit log. */
class RunTenantBackup implements \App\Tenancy\PlatformJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3700;

    public function __construct(public string $slug, public ?int $staffId = null, public ?string $staffEmail = null) {}

    public function handle(): void
    {
        $code = Artisan::call('backup:run', ['--tenant' => $this->slug]);
        $out = trim(Artisan::output());

        $staff = $this->staffId ? \App\Platform\PlatformStaff::find($this->staffId) : null;
        PlatformAudit::record($staff, 'tenant.backup.finished', $this->slug, [], $code === 0 ? 'ok' : 'failed', $out, 'queue', $this->staffEmail);
    }

    public function failed(\Throwable $e): void
    {
        $staff = $this->staffId ? \App\Platform\PlatformStaff::find($this->staffId) : null;
        PlatformAudit::record($staff, 'tenant.backup.finished', $this->slug, [], 'failed', $e->getMessage(), 'queue', $this->staffEmail);
    }
}
