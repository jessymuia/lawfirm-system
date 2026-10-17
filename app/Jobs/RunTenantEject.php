<?php

namespace App\Jobs;

use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Portability\PortabilityRunner;
use App\Tenancy\PlatformJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** PLAT-9: eject one firm to a checksummed bundle, requested by platform staff. Outcome (path, sha256) goes to the platform audit log. */
class RunTenantEject implements PlatformJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public string $slug, public ?int $staffId = null, public ?string $staffEmail = null) {}

    public function handle(PortabilityRunner $runner): void
    {
        $path = rtrim((string) config('portability.directory'), '/').'/'.$this->slug.'-'.now()->format('Ymd-His').'.zip';
        $r = $runner->exportZip($this->slug, $path);
        $m = $r['manifest'];

        PlatformAudit::record($this->staff(), 'tenant.eject.finished', $this->slug,
            ['path' => $path, 'sha256' => $r['sha256'], 'rows' => array_sum(array_column($m['tables'], 'rows')), 'documents' => $m['files']['count'], 'missing_documents' => $m['files']['missing']],
            'ok', null, 'queue', $this->staffEmail);
    }

    public function failed(\Throwable $e): void
    {
        PlatformAudit::record($this->staff(), 'tenant.eject.finished', $this->slug, [], 'failed', $e->getMessage(), 'queue', $this->staffEmail);
    }

    private function staff(): ?PlatformStaff
    {
        return $this->staffId ? PlatformStaff::find($this->staffId) : null;
    }
}
