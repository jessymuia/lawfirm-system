<?php

namespace Tests\Isolation;

use App\Models\Document;
use Illuminate\Http\UploadedFile;

/** TEN-10 / TEN-12: stored documents are namespaced per firm and unreachable from another firm. */
class FilesIsolationTest extends IsolationTestCase
{
    private function store(string $slug, int $caseId, string $title): array
    {
        $secret = $this->canary($slug)." secret contents of {$title}";
        $r = $this->upload($slug, '/documents', ['title' => $title, 'case_id' => $caseId], 'file', UploadedFile::fake()->createWithContent("{$title}.pdf", "%PDF-1.4\n{$secret}\n"));
        $r->assertOk();

        return [$r->json('data.id'), $r->json('data.filepath'), $secret];
    }

    public function test_every_stored_object_key_carries_the_firms_prefix_and_nothing_else_is_stored_for_it(): void
    {
        [$ida, $pathA] = $this->store('a', 1, 'a-doc');
        [, $pathB1] = $this->store('b', 1, 'b-doc-1');
        [, $pathB2] = $this->store('b', 5, 'b-doc-5');

        $this->assertStringStartsWith('tenants/a/', $pathA);
        $this->assertStringStartsWith('tenants/b/', $pathB1);
        $this->assertStringStartsWith('tenants/b/', $pathB2);
        $this->assertFileExists(storage_path('app/'.$pathA));
        $this->assertStringContainsString('CANARY-A', file_get_contents(storage_path('app/'.$pathA)));
        $this->assertStringNotContainsString('CANARY-B', file_get_contents(storage_path('app/'.$pathA)));

        // the files that exist under a firm's prefix are exactly the files its own rows point to
        foreach (['a', 'b'] as $slug) {
            $onDisk = collect(\Illuminate\Support\Facades\File::allFiles(storage_path("app/tenants/{$slug}")))->map(fn ($f) => 'tenants/'.$slug.'/'.str_replace(storage_path("app/tenants/{$slug}/"), '', $f->getPathname()))->sort()->values()->all();
            $rows = $this->inFirm($slug, fn () => Document::pluck('filepath')->sort()->values()->all());
            $this->assertSame($rows, $onDisk, "firm {$slug}: files on disk and rows must match");
        }
    }

    public function test_a_document_id_that_exists_only_in_the_other_firm_cannot_be_previewed(): void
    {
        $this->store('a', 1, 'a-doc');
        $this->store('b', 1, 'b-doc-1');
        [$idB2] = $this->store('b', 5, 'b-doc-5');
        $onlyB = $this->idsOnlyIn('b', 'documents', 'a');
        $this->assertContains($idB2, $onlyB);

        foreach ($onlyB as $id) {
            $this->api('a', 'GET', '/preview?id='.$id)->assertNotFound();
            $this->api('a', 'DELETE', "/documents/{$id}")->assertNotFound();
        }
        $this->inFirm('b', fn () => $this->assertSame(2, Document::count()));
        $this->assertCount(2, \Illuminate\Support\Facades\File::allFiles(storage_path('app/tenants/b')));
    }

    public function test_the_same_document_id_serves_the_callers_own_file(): void
    {
        [$ida] = $this->store('a', 1, 'a-doc');
        [$idb] = $this->store('b', 1, 'b-doc-1');
        $this->assertSame($ida, $idb, 'ids deliberately collide');

        $a = $this->api('a', 'GET', '/preview?id='.$ida)->assertOk();
        $b = $this->api('b', 'GET', '/preview?id='.$idb)->assertOk();
        $this->assertStringContainsString('tenants/a/', $a->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('tenants/b/', $b->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('CANARY-A', file_get_contents($a->baseResponse->getFile()->getPathname()));
    }

    public function test_document_listings_and_versions_never_cross_firms(): void
    {
        [$ida] = $this->store('a', 1, 'a-doc');
        [$idb] = $this->store('b', 5, 'b-doc-5');
        $this->assertNoLeak($this->api('a', 'GET', '/documents/5?all_versions=1'), 'b');      // case 5 exists only in B
        $this->assertNoLeak($this->api('a', 'GET', "/documents/{$idb}/versions"), 'b');
        $this->assertNoLeak($this->api('a', 'GET', '/documents/1'), 'b');
        // a version upload in A cannot attach to a document group that exists only in B
        $this->upload('a', '/documents', ['title' => 'x', 'case_id' => 1, 'document_group_id' => $idb + 10], 'file', UploadedFile::fake()->createWithContent('x.pdf', "%PDF-1.4\nx"))->assertStatus(422);
    }
}
