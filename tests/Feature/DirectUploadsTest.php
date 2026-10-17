<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\ExtractDocumentText;
use App\Models\Cases;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-22: documents sent straight to the firm's bucket with a pre-signed URL, then checked and filed. */
class DirectUploadsTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n";

    private User $lawyer;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ExtractDocumentText::class]);
        config([
            'filesystems.disks.s3' => ['driver' => 's3', 'key' => 'AKIATEST', 'secret' => 'secret', 'region' => 'eu-west-1', 'bucket' => 'firm-files',
                'endpoint' => 'https://s3.example.test', 'use_path_style_endpoint' => true, 'throw' => false],
            'tenancy.active_files_disk' => 's3',
        ]);
        $this->lawyer = $this->userWithPermissions([P::CREATE_DOCUMENTS, P::LIST_DOCUMENTS]);
        $this->matter = Cases::factory()->create(['assigned_to' => $this->lawyer->id]);
    }

    private function begin(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->lawyer)->postJson('/api/v1/documents/uploads', $over + [
            'case_id' => $this->matter->id, 'title' => 'Witness statement', 'filename' => 'statement.pdf', 'mimetype' => 'application/pdf',
            'size' => strlen(self::PDF),
        ]);
    }

    /** What the browser does with the answer: PUT the bytes at the reserved key (a fake bucket stands in for S3). */
    private function send(string $id, string $bytes): string
    {
        $path = DB::table('document_uploads')->where('token', $id)->value('path');
        Storage::disk('s3')->put($path, $bytes);

        return $path;
    }

    public function test_a_document_goes_straight_to_the_bucket_and_is_filed_once_checked(): void
    {
        $u = $this->begin()->assertCreated()->json('data');
        $this->assertSame('https://s3.example.test/firm-files', $u['url']);
        $f = $u['fields'];
        $this->assertStringContainsString('cases/docs/', $f['key'], 'under the firm prefix (TEN-12)');
        $this->assertStringEndsWith('_statement.pdf', $f['key']);
        $this->assertSame(['application/pdf', 'AWS4-HMAC-SHA256'], [$f['Content-Type'], $f['X-Amz-Algorithm']]);
        $this->assertNotEmpty($f['X-Amz-Signature']);
        // the policy S3 enforces: this key, this type, exactly this many bytes, for fifteen minutes
        $policy = json_decode(base64_decode($f['Policy']), true);
        $this->assertContains(['eq', '$key', $f['key']], $policy['conditions']);
        $this->assertContains(['eq', '$Content-Type', 'application/pdf'], $policy['conditions']);
        $this->assertContains(['content-length-range', strlen(self::PDF), strlen(self::PDF)], $policy['conditions']);
        $this->assertEqualsWithDelta(now()->addMinutes(15)->timestamp, strtotime($policy['expiration']), 5);

        Storage::fake('s3');
        $path = $this->send($u['id'], self::PDF);
        $doc = $this->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertCreated()->json('data');

        $d = Document::find($doc['id']);
        $this->assertSame(['Witness statement', 's3', $path, hash('sha256', self::PDF), 'application/pdf', strlen(self::PDF), 'pdf', 1],
            [$d->title, $d->disk, $d->full_path, $d->checksum, $d->mimetype, (int) $d->filesize, $d->extension, (int) $d->version]);
        $this->assertSame($d->id, (int) $d->document_group_id);
        Bus::assertDispatched(ExtractDocumentText::class, fn ($j) => true);   // searchable like any upload (FUN-8)

        $this->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertNotFound();   // once only

        // a new version of the same document
        $u2 = $this->begin(['document_group_id' => $d->id, 'size' => strlen(self::PDF."%v2\n")])->assertCreated()->json('data');
        $this->send($u2['id'], self::PDF."%v2\n");
        $this->begin();   // unrelated
        $v2 = Document::find($this->postJson("/api/v1/documents/uploads/{$u2['id']}/complete")->assertCreated()->json('data.id'));
        $this->assertSame([2, $d->id], [(int) $v2->version, (int) $v2->document_group_id]);
        $this->assertFalse((bool) $d->fresh()->is_current);
    }

    public function test_what_arrives_must_be_what_was_announced(): void
    {
        $u = $this->begin()->json('data');
        Storage::fake('s3');
        $this->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertStatus(409)->assertJsonPath('code', 'upload_missing');

        $path = $this->send($u['id'], self::PDF.'extra bytes');   // not the length announced
        $this->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertUnprocessable()->assertJsonPath('code', 'upload_rejected');
        Storage::disk('s3')->assertMissing($path);
        $this->assertSame(0, Document::count());

        // the type is read from the content, not taken from the browser
        $bad = "\x7fELF\x02\x01\x01".str_repeat("\0", 60);
        $u = $this->begin(['size' => strlen($bad)])->json('data');
        $this->send($u['id'], $bad);
        $this->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertUnprocessable()->assertJsonPath('code', 'upload_rejected');
        $this->assertSame(0, Document::count());
    }

    public function test_an_upload_belongs_to_whoever_began_it_and_expires(): void
    {
        $u = $this->begin()->json('data');
        Storage::fake('s3');
        $this->send($u['id'], self::PDF);

        $colleague = $this->userWithPermissions([P::CREATE_DOCUMENTS]);
        $this->actingAs($colleague)->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertNotFound();

        $this->travel(16)->minutes();
        $this->actingAs($this->lawyer)->postJson("/api/v1/documents/uploads/{$u['id']}/complete")->assertStatus(410)->assertJsonPath('code', 'upload_expired');
    }

    public function test_requests_are_checked_before_anything_is_reserved(): void
    {
        $this->begin(['mimetype' => 'application/x-msdownload'])->assertUnprocessable()->assertJsonValidationErrors('mimetype');
        config(['documents.direct_uploads.max_mb' => 1]);
        $this->begin(['size' => 2 * 1024 * 1024])->assertUnprocessable()->assertJsonPath('errors.size.0', 'The file is larger than 1 MB.');

        $this->begin([], $this->userWithPermissions([P::LIST_DOCUMENTS]))->assertForbidden();

        config(['matters.access' => 'restricted']);
        $this->begin([], $this->userWithPermissions([P::CREATE_DOCUMENTS]))->assertNotFound();   // a matter they cannot open
        $this->assertSame(0, DB::table('document_uploads')->count());
    }

    public function test_without_a_bucket_the_app_says_to_upload_through_the_api(): void
    {
        config(['tenancy.active_files_disk' => null, 'filesystems.disks.s3.bucket' => null]);   // no bucket: the firm's files are local
        $this->begin()->assertStatus(409)->assertJsonPath('code', 'direct_uploads_unavailable');

        config(['filesystems.disks.s3.bucket' => 'firm-files', 'documents.direct_uploads.enabled' => false]);
        $this->begin()->assertStatus(409)->assertJsonPath('code', 'direct_uploads_unavailable');
    }

    public function test_abandoned_uploads_are_pruned_from_the_bucket(): void
    {
        $abandoned = $this->begin()->json('data');
        $done = $this->begin()->json('data');
        Storage::fake('s3');
        $left = $this->send($abandoned['id'], self::PDF);
        $this->send($done['id'], self::PDF);
        $this->postJson("/api/v1/documents/uploads/{$done['id']}/complete")->assertCreated();
        $fresh = $this->begin()->json('data');

        $this->travel(25)->minutes();
        $fresh2 = $this->begin()->json('data');
        $this->assertSame(0, Artisan::call('documents:prune-uploads'));
        $this->assertStringContainsString('Deleted 2 abandoned upload(s).', Artisan::output());
        Storage::disk('s3')->assertMissing($left);
        $this->assertSame([$done['id'], $fresh2['id']], DB::table('document_uploads')->orderBy('id')->pluck('token')->all());
        $this->assertNotNull($fresh);
        $this->assertSame(1, Document::count(), 'filed documents are never touched');
    }
}
