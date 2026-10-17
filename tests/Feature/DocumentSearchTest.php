<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Documents\TextExtractor;
use App\Jobs\BackfillDocumentText;
use App\Models\Cases;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;
use ZipArchive;

/** FUN-8: reading the text inside documents (and OCR for scans), and searching it within what the searcher may open. */
class DocumentSearchTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $u;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // the scan path works on every database inside the test transaction; DocumentFullTextTest covers the MySQL index
        config(['documents.search.engine' => 'scan']);
        $this->u = $this->userWithPermissions([P::LIST_DOCUMENTS, P::CREATE_DOCUMENTS, P::LIST_CASES, P::ACCESS_ALL_MATTERS]);
        $this->matter = Cases::factory()->create(['case_number' => 'ELC 7/2026']);
    }

    private function upload(UploadedFile $file, string $title, ?Cases $m = null): int
    {
        return $this->actingAs($this->u)->post('/api/v1/documents', ['file' => $file, 'title' => $title, 'case_id' => ($m ?? $this->matter)->id], ['Accept' => 'application/json'])
            ->assertOk()->json('data.id');
    }

    private function docx(string $text): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $paras = implode('', array_map(fn ($p) => '<w:p><w:r><w:t>'.htmlspecialchars($p).'</w:t></w:r></w:p>', explode("\n", $text)));
        $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="w"><w:body>'.$paras.'</w:body></w:document>');
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->close();

        return new UploadedFile($path, 'lease.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    private function state(int $id): object
    {
        return DB::table('document_texts')->where('document_id', $id)->first();
    }

    public function test_uploads_are_read_and_found_by_the_words_inside_them(): void
    {
        $txt = $this->upload(UploadedFile::fake()->createWithContent('note.txt', "Meeting with the surveyor about the boundary beacon on plot LR 209/1145.\nNext steps agreed."), 'File note');
        $docx = $this->upload($this->docx("LEASE AGREEMENT\nThe tenant shall pay rent quarterly in advance.\nForfeiture on breach of covenant."), 'Lease');

        $this->assertSame(['done', 'text'], [$this->state($txt)->state, $this->state($txt)->method]);
        $this->assertSame('docx', $this->state($docx)->method);

        $hit = $this->getJson('/api/v1/documents/search?q=boundary beacon')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $txt)->assertJsonPath('data.data.0.case_number', 'ELC 7/2026')->json('data.data.0.snippet');
        $this->assertStringContainsString('«boundary» «beacon»', $hit);
        $this->getJson('/api/v1/documents/search?q=forfeiture covenant')->assertJsonPath('data.data.0.id', $docx);
        $this->getJson('/api/v1/documents/search?q=forfeiture surveyor')->assertJsonPath('data.total', 0);   // every word must be in one document

        // global search finds a document by what it says, not only its name
        $this->getJson('/api/v1/search?q=quarterly')->assertOk()->assertJsonPath('data.documents.0.id', $docx);
    }

    public function test_scans_and_photos_are_read_by_ocr(): void
    {
        Process::fake([
            '*pdftotext*' => Process::result(''),   // no text layer: a scan
            '*pdftoppm*' => function ($process) {
                $prefix = end($process->command);
                file_put_contents($prefix.'-01.png', 'png');

                return Process::result();
            },
            '*tesseract*' => Process::result("REPUBLIC OF KENYA\nTitle deed for parcel Kajiado/Kitengela/4471"),
        ]);
        $scan = $this->upload(UploadedFile::fake()->create('deed.pdf', 30, 'application/pdf'), 'Title deed (scan)');
        $this->assertSame(['done', 'ocr'], [$this->state($scan)->state, $this->state($scan)->method]);
        $this->getJson('/api/v1/documents/search?q=Kitengela')->assertJsonPath('data.data.0.id', $scan)->assertJsonPath('data.data.0.ocr', true);

        config(['documents.ocr.enabled' => false]);
        $photo = $this->upload(UploadedFile::fake()->image('id.jpg'), 'ID photo');
        $this->assertSame('empty', $this->state($photo)->state);
        $this->getJson("/api/v1/cases/{$this->matter->id}/document-text")->assertOk()->assertJsonPath("data.{$photo}.state", 'empty')
            ->assertJsonPath("data.{$scan}.method", 'ocr');
    }

    public function test_a_pdf_with_text_is_read_and_a_broken_file_is_recorded_not_thrown(): void
    {
        Process::fake(['*pdftotext*' => Process::result('JUDGMENT. The appeal is allowed with costs to the appellant.')]);
        $pdf = $this->upload(UploadedFile::fake()->create('judgment.pdf', 10, 'application/pdf'), 'Judgment');
        $this->assertSame('pdf', $this->state($pdf)->method);

        $bad = $this->upload(UploadedFile::fake()->createWithContent('broken.docx', 'not a zip'), 'Broken');
        $this->assertSame('failed', $this->state($bad)->state);
        $legacy = (new TextExtractor)->extract(__FILE__, 'application/msword', 'doc');
        $this->assertSame(['unsupported', 'Text cannot be read from .doc files.'], [$legacy['state'], $legacy['error']]);
    }

    public function test_search_never_reaches_documents_the_searcher_cannot_open(): void
    {
        $secret = Cases::factory()->create(['case_number' => 'WALLED-1']);
        $this->upload(UploadedFile::fake()->createWithContent('a.txt', 'Settlement offer of KES 4,000,000 without prejudice'), 'Offer', $secret);
        $this->upload(UploadedFile::fake()->createWithContent('b.txt', 'Our settlement position is unchanged'), 'Position');

        config(['matters.access' => 'restricted']);
        $lawyer = $this->userWithPermissions([P::LIST_DOCUMENTS, P::LIST_CASES]);
        DB::table('cases')->where('id', $this->matter->id)->update(['assigned_to' => $lawyer->id]);
        $this->actingAs($lawyer)->getJson('/api/v1/documents/search?q=settlement')->assertOk()->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.case_number', 'ELC 7/2026');
        $this->getJson("/api/v1/cases/{$secret->id}/document-text")->assertNotFound();
    }

    public function test_only_the_current_version_is_searched_and_old_documents_are_backfilled(): void
    {
        $v1 = $this->upload(UploadedFile::fake()->createWithContent('draft.txt', 'first draft mentions arbitration'), 'Contract');
        $group = Document::find($v1)->document_group_id;
        $this->actingAs($this->u)->post('/api/v1/documents', ['file' => UploadedFile::fake()->createWithContent('final.txt', 'final version mentions mediation'),
            'title' => 'Contract', 'case_id' => $this->matter->id, 'document_group_id' => $group], ['Accept' => 'application/json'])->assertOk();
        $this->getJson('/api/v1/documents/search?q=arbitration')->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/documents/search?q=mediation')->assertJsonPath('data.total', 1);

        // documents from before FUN-8 have no text row until the backfill reads them
        DB::table('document_texts')->delete();
        $this->assertSame(1, (new BackfillDocumentText)->handle(), 'current versions only');
        $this->assertSame(1, DB::table('document_texts')->count());
        $this->assertSame(0, (new BackfillDocumentText)->handle(), 'idempotent');
    }

    public function test_permission_and_input(): void
    {
        $this->actingAs($this->userWithPermissions([P::LIST_CASES]))->getJson('/api/v1/documents/search?q=anything')->assertForbidden();
        $this->actingAs($this->u)->getJson('/api/v1/documents/search?q=a')->assertUnprocessable();
        $id = $this->upload(UploadedFile::fake()->createWithContent('n.txt', 'hello world'), 'N');
        $this->postJson("/api/v1/documents/{$id}/reindex")->assertStatus(202);
    }
}
