<?php

namespace App\Jobs;

use App\Exports\FirmExportBuilder;
use App\Mail\FirmExportReady;
use App\Models\FirmExport;
use App\Queue\BulkWork;
use App\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * ENG-21: builds the firm-wide export off the request path, stores it under the firm's storage prefix, and emails
 * the requester a link (the link itself is signed and expiring; see ExportController::firmExportStatus). Runs in the
 * firm it was requested from (TEN-3) and with no logged-in user, i.e. unscoped: the request already checked that the
 * requester may see every matter.
 */
class BuildFirmExport implements ShouldQueue
{
    use BulkWork, Dispatchable, InteractsWithQueue, Queueable;

    public int $maxExceptions = 1;   // ENG-20: real failures; waiting for the firm's bulk slot does not count

    public int $timeout = 3600;

    public function __construct(public int $exportId)
    {
        $this->onBulkLane();   // ENG-20
    }

    public function handle(FirmExportBuilder $builder): void
    {
        $export = FirmExport::findOrFail($this->exportId);
        $export->update(['status' => 'running']);

        $zip = $builder->build();
        try {
            $path = app(TenantContext::class)->storagePrefix('exports/firm-'.$export->id.'-'.bin2hex(random_bytes(8)).'.zip');
            $stream = fopen($zip, 'rb');
            Storage::disk('local')->writeStream($path, $stream);
            is_resource($stream) && fclose($stream);
            $export->update([
                'status' => 'ready', 'path' => $path, 'size' => filesize($zip), 'ready_at' => now(),
                'expires_at' => now()->addHours((int) config('exports.firm_link_hours', 24)),
            ]);
        } finally {
            @unlink($zip);
        }

        if ($export->requester?->email) {
            Mail::to($export->requester->email)->send(new FirmExportReady($export));
        }
    }

    public function failed(Throwable $e): void
    {
        FirmExport::whereKey($this->exportId)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
    }
}
