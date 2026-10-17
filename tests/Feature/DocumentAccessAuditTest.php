<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Document;
use App\Models\DocumentAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** Every preview attempt lands on the document's access log, refused ones included (found missing by the E2E suite). */
class DocumentAccessAuditTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    protected function tearDown(): void
    {
        @unlink(storage_path('app/testing-preview/a.txt'));
        @rmdir(storage_path('app/testing-preview'));
        parent::tearDown();
    }

    private function document(): Document
    {
        // preview() serves from storage_path('app/'), so the file must really be there; removed in tearDown.
        $case = Cases::factory()->create();
        @mkdir(storage_path('app/testing-preview'), 0755, true);
        file_put_contents(storage_path('app/testing-preview/a.txt'), 'hello');

        return Document::create(['case_id' => $case->id, 'title' => 'a', 'filename' => 'a.txt', 'filepath' => 'testing-preview/a.txt', 'full_path' => 'testing-preview/a.txt',
            'disk' => 'local', 'mimetype' => 'text/plain', 'filesize' => 5, 'extension' => 'txt', 'version' => 1, 'is_current' => true]);
    }

    public function test_a_refused_preview_is_recorded_as_unsuccessful(): void
    {
        $doc = $this->document();
        $clerk = $this->userWithPermissions([P::LIST_CASES]);

        $this->actingAs($clerk)->getJson('/api/v1/preview?id='.$doc->id)->assertForbidden();

        $row = DocumentAccess::where('document_id', $doc->id)->sole();
        $this->assertSame('Unsuccessfull', $row->outcome);
        $this->assertSame($clerk->id, (int) $row->accessed_by);
    }

    public function test_a_refused_preview_of_a_missing_document_records_nothing_and_still_refuses(): void
    {
        $this->actingAs($this->userWithPermissions([P::LIST_CASES]))->getJson('/api/v1/preview?id=999999')->assertForbidden();

        $this->assertSame(0, DocumentAccess::count());
    }

    public function test_an_allowed_preview_is_recorded_once_as_success(): void
    {
        $doc = $this->document();

        $this->actingAs($this->userWithPermissions([P::VIEW_DOCUMENTS]))->get('/api/v1/preview?id='.$doc->id)->assertOk();

        $this->assertSame(['success'], DocumentAccess::where('document_id', $doc->id)->pluck('outcome')->all());
    }
}
