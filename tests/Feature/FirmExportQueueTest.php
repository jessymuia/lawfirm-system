<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\BuildFirmExport;
use App\Mail\FirmExportReady;
use App\Models\Client;
use App\Models\FirmExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-21: the firm-wide export runs as a job, ends in a signed, expiring download and an email. */
class FirmExportQueueTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function exporter()
    {
        return $this->userWithPermissions([P::EXPORT_FIRM_DATA]);
    }

    public function test_requesting_queues_a_job_and_answers_at_once(): void
    {
        Bus::fake();
        $user = $this->exporter();

        $id = $this->actingAs($user)->postJson('/api/v1/export/firm-data')->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.download_url', null)->json('data.id');

        Bus::assertDispatched(BuildFirmExport::class, fn ($job) => $job->exportId === $id);
        $this->assertSame($user->id, FirmExport::find($id)->requested_by);
    }

    public function test_end_to_end_the_job_builds_stores_and_emails_and_the_signed_link_downloads(): void
    {
        Mail::fake();
        Storage::fake('local');
        Client::factory()->create(['name' => 'Exported Client Ltd']);
        $user = $this->exporter();

        // sync queue: the job runs inside the request
        $id = $this->actingAs($user)->postJson('/api/v1/export/firm-data')->assertStatus(202)->json('data.id');

        $export = FirmExport::find($id);
        $this->assertSame('ready', $export->status);
        Storage::disk('local')->assertExists($export->path);
        $this->assertStringContainsString('exports/firm-'.$id, $export->path);
        Mail::assertSent(FirmExportReady::class, fn ($m) => $m->hasTo($user->email));

        $status = $this->getJson("/api/v1/export/firm-data/{$id}")->assertOk()->assertJsonPath('data.status', 'ready');
        $url = $status->json('data.download_url');
        $this->assertStringContainsString('signature=', $url);

        $download = $this->get($url)->assertOk();
        $this->assertStringContainsString('firm-export-', (string) $download->headers->get('content-disposition'));
    }

    public function test_the_download_needs_a_valid_signature_and_the_requesters_own_session(): void
    {
        Mail::fake();
        Storage::fake('local');
        $user = $this->exporter();
        $id = $this->actingAs($user)->postJson('/api/v1/export/firm-data')->json('data.id');
        $url = $this->getJson("/api/v1/export/firm-data/{$id}")->json('data.download_url');

        $this->get("/api/v1/export/firm-data/{$id}/download")->assertForbidden();           // unsigned
        $this->get($url.'x')->assertForbidden();                                              // tampered

        $colleague = $this->exporter();
        $this->actingAs($colleague)->get($url)->assertNotFound();                             // someone else's export
        $this->actingAs($colleague)->getJson("/api/v1/export/firm-data/{$id}")->assertNotFound();
    }

    public function test_expired_exports_are_refused_and_pruned(): void
    {
        Mail::fake();
        Storage::fake('local');
        $user = $this->exporter();
        $id = $this->actingAs($user)->postJson('/api/v1/export/firm-data')->json('data.id');
        $url = $this->getJson("/api/v1/export/firm-data/{$id}")->json('data.download_url');
        $path = FirmExport::find($id)->path;

        $this->travel(25)->hours();

        $this->getJson("/api/v1/export/firm-data/{$id}")->assertJsonPath('data.status', 'expired')->assertJsonPath('data.download_url', null);
        $this->assertSame(0, Artisan::call('exports:prune'));
        Storage::disk('local')->assertMissing($path);
        $this->assertSame('expired', FirmExport::find($id)->status);
        $this->get($url)->assertForbidden();   // the signed URL itself expired long before
    }

    public function test_a_failed_build_is_reported_without_internals(): void
    {
        $user = $this->exporter();
        $export = FirmExport::create(['requested_by' => $user->id, 'status' => 'running']);

        (new BuildFirmExport($export->id))->failed(new \RuntimeException('disk full at /var/secret/path'));

        $this->actingAs($user)->getJson("/api/v1/export/firm-data/{$export->id}")
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonMissingPath('data.path');
        $this->assertStringNotContainsString('/var/secret', $this->getJson("/api/v1/export/firm-data/{$export->id}")->getContent());
    }

    public function test_requesting_needs_the_permission_and_full_matter_access(): void
    {
        $this->actingAs($this->userWithPermissions([P::LIST_CLIENTS]))->postJson('/api/v1/export/firm-data')->assertForbidden();

        config(['matters.access' => 'restricted']);
        $this->actingAs($this->exporter())->postJson('/api/v1/export/firm-data')->assertForbidden();
    }
}
