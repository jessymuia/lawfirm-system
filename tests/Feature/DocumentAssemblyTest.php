<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Documents\DocxTemplate;
use App\Mail\SignatureRequested;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;
use ZipArchive;

/** FUN-14: documents assembled from Word templates, and electronic signature with a certificate. */
class DocumentAssemblyTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $u;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['documents.text.enabled' => false]);
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00', 'Africa/Nairobi'));
        $this->u = $this->userWithPermissions([P::MANAGE_DOCUMENT_TEMPLATES, P::LIST_DOCUMENTS, P::CREATE_DOCUMENTS, P::LIST_CASES, P::ACCESS_ALL_MATTERS]);
        $this->u->forceFill(['name' => 'Otieno Advocate'])->save();
        $client = Client::factory()->create(['name' => 'Mwangi & Sons Ltd', 'address' => 'P.O. Box 1, Nairobi']);
        $this->matter = Cases::factory()->create(['client_id' => $client->id, 'case_number' => 'HCCC 12/2026', 'assigned_to' => $this->u->id]);
    }

    /** A Word file whose body splits fields across runs (as Word does), with a field in the header too. */
    private function docx(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $w = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?><w:document '.$w.'><w:body>'
            .'<w:p><w:pPr><w:jc w:val="center"/></w:pPr><w:r><w:rPr><w:b/></w:rPr><w:t>DEMAND LETTER – </w:t></w:r><w:r><w:t>{{ matter.case_</w:t></w:r><w:r><w:rPr><w:i/></w:rPr><w:t>number }}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">To: {{client.name}}, {{ client.address }}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Pay KES {{ ask: Amount }} by {{ask:Deadline}}. Dated {{ today.long }}. {{ matter.nonsense }}</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>An untouched paragraph.</w:t></w:r></w:p>'
            .'</w:body></w:document>');
        $zip->addFromString('word/header1.xml', '<?xml version="1.0"?><w:hdr '.$w.'><w:p><w:r><w:t>{{ firm.name }} · {{lawyer.name}}</w:t></w:r></w:p></w:hdr>');
        $zip->close();

        return new UploadedFile($path, 'Demand letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    private function template(): int
    {
        return $this->actingAs($this->u)->post('/api/v1/document-templates', ['name' => 'Demand letter', 'category' => 'Litigation', 'file' => $this->docx()], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.unknown_fields', ['matter.nonsense'])->json('data.id');
    }

    private function xml(Document $doc, string $part = 'word/document.xml'): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'out');
        file_put_contents($tmp, Storage::disk('local')->get($doc->full_path));
        $zip = new ZipArchive;
        $zip->open($tmp);
        $xml = (string) $zip->getFromName($part);
        $zip->close();

        return $xml;
    }

    public function test_a_template_lists_its_fields_and_questions(): void
    {
        $this->template();
        $t = $this->getJson('/api/v1/document-templates')->assertOk()->json('data.templates.0');
        $this->assertSame(['matter.case_number', 'client.name', 'client.address', 'ask:Amount', 'ask:Deadline', 'today.long', 'matter.nonsense', 'firm.name', 'lawyer.name'], $t['fields']);
        $this->assertSame(['Amount', 'Deadline'], $t['questions']);
        $this->post('/api/v1/document-templates', ['name' => 'Bad', 'file' => UploadedFile::fake()->create('x.docx', 5, 'application/zip')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($this->userWithPermissions([P::LIST_DOCUMENTS]))->post('/api/v1/document-templates', ['name' => 'X', 'file' => $this->docx()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_a_matter_document_is_made_from_the_template_and_the_answers(): void
    {
        $tid = $this->template();
        $this->postJson("/api/v1/cases/{$this->matter->id}/documents/from-template", ['template_id' => $tid, 'answers' => ['Amount' => '1,500,000']])
            ->assertUnprocessable()->assertJsonPath('data.missing', ['Deadline']);
        $id = $this->postJson("/api/v1/cases/{$this->matter->id}/documents/from-template", ['template_id' => $tid, 'answers' => ['Amount' => '1,500,000', 'Deadline' => '<14 days>']])
            ->assertCreated()->assertJsonPath('data.title', 'Demand letter – HCCC 12/2026')->json('data.id');

        $doc = Document::find($id);
        $this->assertSame($this->matter->id, (int) $doc->case_id);
        $xml = $this->xml($doc);
        $this->assertStringContainsString('DEMAND LETTER – HCCC 12/2026', $xml, 'a field split across runs is filled');
        $this->assertStringContainsString('<w:jc w:val="center"/>', $xml, 'paragraph formatting kept');
        $this->assertStringContainsString('<w:rPr><w:b/></w:rPr>', $xml, 'the first run\'s formatting kept');
        $this->assertStringContainsString('To: Mwangi &amp; Sons Ltd, P.O. Box 1, Nairobi', $xml, 'values are escaped');
        $this->assertStringContainsString('Pay KES 1,500,000 by &lt;14 days&gt;. Dated 5 October 2026. [matter.nonsense]', $xml, 'unknown fields stay visible');
        $this->assertStringContainsString('<w:p><w:r><w:t>An untouched paragraph.</w:t></w:r></w:p>', $xml);
        $this->assertStringContainsString(config('app.name').' · Otieno Advocate', $this->xml($doc, 'word/header1.xml'));
        $this->assertSame(1, DB::table('audits')->where('event', 'document_assembled')->count());

        // archived templates cannot be used; matters the person cannot open cannot receive documents
        $this->deleteJson("/api/v1/document-templates/{$tid}")->assertOk();
        $this->postJson("/api/v1/cases/{$this->matter->id}/documents/from-template", ['template_id' => $tid, 'answers' => ['Amount' => '1', 'Deadline' => '1']])->assertNotFound();
    }

    public function test_the_filler_leaves_drawings_alone(): void
    {
        $src = tempnam(sys_get_temp_dir(), 'd').'.docx';
        $zip = new ZipArchive;
        $zip->open($src, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<w:document><w:body><w:p><w:r><w:drawing><a:graphic>LOGO</a:graphic></w:drawing></w:r><w:r><w:t>{{ firm.name }}</w:t></w:r><w:r><w:t>{{ split</w:t></w:r><w:r><w:t>.field }}</w:t></w:r></w:p></w:body></w:document>');
        $zip->close();
        $out = tempnam(sys_get_temp_dir(), 'o');
        DocxTemplate::fill($src, $out, ['firm.name' => 'Acme Advocates']);
        $zip->open($out);
        $xml = (string) $zip->getFromName('word/document.xml');
        $this->assertStringContainsString('<w:drawing><a:graphic>LOGO</a:graphic></w:drawing>', $xml);
        $this->assertStringContainsString('<w:t>Acme Advocates</w:t>', $xml);
    }

    // ---- e-signature ----

    private function png(): string
    {
        $img = imagecreatetruecolor(200, 60);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 40, 190, 20, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagepng($img);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    private function pdfDocument(): Document
    {
        Storage::disk('local')->put('docs/agreement.pdf', "%PDF-1.4\nSettlement agreement\n%%EOF");

        return Document::create(['title' => 'Settlement agreement', 'case_id' => $this->matter->id, 'version' => 1, 'is_current' => true, 'filename' => 'agreement.pdf',
            'filepath' => 'docs/agreement.pdf', 'full_path' => 'docs/agreement.pdf', 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 30, 'extension' => 'pdf']);
    }

    /** The personal link sent to a signer (captured from the email). */
    private function linkFor(string $email): string
    {
        $mail = collect(Mail::queued(SignatureRequested::class))->filter(fn ($m) => $m->hasTo($email))->last();
        $this->assertNotNull($mail, "no link sent to {$email}");

        return (string) substr((string) parse_url($mail->link, PHP_URL_PATH), strlen('/sign/'));
    }

    public function test_two_people_sign_in_order_and_a_certificate_is_filed(): void
    {
        Mail::fake();
        $doc = $this->pdfDocument();
        $rid = $this->actingAs($this->u)->postJson("/api/v1/cases/{$this->matter->id}/signatures", ['document_id' => $doc->id, 'title' => 'Settlement agreement', 'in_order' => true,
            'signers' => [['name' => 'Jane Client', 'email' => 'jane@client.test'], ['name' => 'John Other', 'email' => 'john@other.test']]])->assertCreated()->json('data.id');
        Mail::assertQueued(SignatureRequested::class, 1);   // in order: only the first is asked
        $jane = $this->linkFor('jane@client.test');

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/sign/{$jane}")->assertOk()->assertJsonPath('data.your_turn', true)->assertJsonPath('data.is_pdf', true)
            ->assertJsonPath('data.sha256', hash('sha256', "%PDF-1.4\nSettlement agreement\n%%EOF"));
        $this->get("/api/v1/sign/{$jane}/document")->assertOk();
        $this->assertNotNull(DB::table('signature_signers')->where('name', 'Jane Client')->value('viewed_at'));

        $this->postJson("/api/v1/sign/{$jane}", ['name' => 'Jane Client', 'signature' => 'data:image/png;base64,AAAA', 'agree' => true])->assertUnprocessable()->assertJsonValidationErrors('signature');
        $this->postJson("/api/v1/sign/{$jane}", ['name' => 'Jane Client', 'signature' => $this->png()])->assertUnprocessable()->assertJsonValidationErrors('agree');
        $this->postJson("/api/v1/sign/{$jane}", ['name' => 'Jane Client', 'signature' => $this->png(), 'agree' => true])->assertOk();
        $this->postJson("/api/v1/sign/{$jane}", ['name' => 'Jane Client', 'signature' => $this->png(), 'agree' => true])->assertStatus(409);

        $john = $this->linkFor('john@other.test');   // his turn now
        $this->postJson("/api/v1/sign/{$john}", ['name' => 'John Other', 'signature' => $this->png(), 'agree' => true])->assertOk();

        $req = DB::table('signature_requests')->find($rid);
        $this->assertSame('completed', $req->state);
        $cert = Document::find($req->certificate_document_id);
        $this->assertSame([$this->matter->id, 'application/pdf'], [(int) $cert->case_id, $cert->mimetype]);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($cert->full_path));
        $this->assertSame(2, DB::table('audits')->where('event', 'document_signed')->count());
        $this->assertNotNull(DB::table('signature_signers')->where('name', 'John Other')->value('ip_address'));

        $this->actingAs($this->u)->getJson("/api/v1/cases/{$this->matter->id}/signatures")->assertOk()
            ->assertJsonPath('data.0.state', 'completed')->assertJsonPath('data.0.signers.1.state', 'signed');
    }

    public function test_the_next_signer_waits_a_changed_file_cannot_be_signed_and_people_can_decline(): void
    {
        Mail::fake();
        $doc = $this->pdfDocument();
        $this->actingAs($this->u)->postJson("/api/v1/cases/{$this->matter->id}/signatures", ['document_id' => $doc->id, 'title' => 'Agreement',
            'signers' => [['name' => 'Ann', 'email' => 'a@x.test'], ['name' => 'Ben', 'email' => 'b@x.test']]])->assertCreated();
        Mail::assertQueued(SignatureRequested::class, 2);   // any order: both asked at once
        $a = $this->linkFor('a@x.test');
        $b = $this->linkFor('b@x.test');

        Storage::disk('local')->put('docs/agreement.pdf', "%PDF-1.4\nSomething else\n%%EOF");   // the file changed after sending
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/sign/{$a}", ['name' => 'Ann', 'signature' => $this->png(), 'agree' => true])->assertStatus(409)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'changed'));

        $this->postJson("/api/v1/sign/{$b}/decline", ['reason' => 'The amount is wrong'])->assertOk();
        $this->assertSame('declined', DB::table('signature_requests')->value('state'));
        $this->postJson("/api/v1/sign/{$a}", ['name' => 'Ann', 'signature' => $this->png(), 'agree' => true])->assertStatus(409);
        $this->getJson('/api/v1/sign/'.str_repeat('x', 48))->assertNotFound();
    }

    public function test_reminders_replace_links_cancelled_and_expired_requests_stop_working(): void
    {
        Mail::fake();
        $doc = $this->pdfDocument();
        $rid = $this->actingAs($this->u)->postJson("/api/v1/cases/{$this->matter->id}/signatures", ['document_id' => $doc->id, 'title' => 'Agreement', 'days' => 3,
            'signers' => [['name' => 'A', 'phone' => '0712345678']]])->assertCreated()->json('data.id');
        $first = DB::table('signature_signers')->value('token_hash');
        $this->postJson("/api/v1/cases/{$this->matter->id}/signatures/{$rid}/remind")->assertOk();
        $this->assertNotSame($first, DB::table('signature_signers')->value('token_hash'), 'a reminder sends a fresh link');

        $this->postJson("/api/v1/cases/{$this->matter->id}/signatures/{$rid}/cancel")->assertOk();
        $this->postJson("/api/v1/cases/{$this->matter->id}/signatures/{$rid}/cancel")->assertStatus(409);

        $this->postJson("/api/v1/cases/{$this->matter->id}/signatures", ['document_id' => $doc->id, 'title' => 'Again', 'days' => 1,
            'signers' => [['name' => 'B', 'email' => 'b@x.test']]])->assertCreated();
        $b = $this->linkFor('b@x.test');
        $this->travel(2)->days();
        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/sign/{$b}")->assertOk()->assertJsonPath('data.request_state', 'expired');

        $this->actingAs($this->u)->postJson("/api/v1/cases/{$this->matter->id}/signatures", ['document_id' => $doc->id, 'title' => 'X', 'signers' => [['name' => 'No contact']]])
            ->assertUnprocessable();
    }
}
