<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * FUN-8 on MySQL/MariaDB: the real full-text index. InnoDB indexes rows only when they are committed, so this test does
 * not run inside the usual rollback transaction; it asks for a fresh database for whatever runs next instead.
 */
class DocumentFullTextTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    /** @var array<int, string|null> no wrapping transaction: the index must see committed rows */
    protected array $connectionsToTransact = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The full-text index exists on MySQL/MariaDB only.');
        }
    }

    protected function tearDown(): void
    {
        RefreshDatabaseState::$migrated = false;   // this test committed rows: the next test class starts from a fresh database
        parent::tearDown();
    }

    public function test_the_full_text_index_ranks_requires_every_word_and_honours_phrases(): void
    {
        Storage::fake('local');
        $u = $this->userWithPermissions([P::LIST_DOCUMENTS, P::CREATE_DOCUMENTS, P::ACCESS_ALL_MATTERS]);
        $m = Cases::factory()->create();
        $up = fn (string $name, string $text) => $this->actingAs($u)->post('/api/v1/documents',
            ['file' => UploadedFile::fake()->createWithContent($name, $text), 'title' => $name, 'case_id' => $m->id], ['Accept' => 'application/json'])->assertOk()->json('data.id');

        $lease = $up('lease.txt', 'The tenant covenants to pay rent, and rent arrears carry interest. Forfeiture follows any breach of covenant by the tenant.');
        $memo = $up('memo.txt', 'Internal memo on the rent review clause and service charge.');
        $up('other.txt', 'Minutes of the board meeting on the merger.');

        $search = fn (string $q) => $this->getJson('/api/v1/documents/search?q='.urlencode($q))->assertOk()->assertJsonPath('data.engine', 'fulltext');
        $this->assertSame([$lease, $memo], array_column($search('rent')->json('data.data'), 'id'), 'both mention rent; the lease more often');
        $this->assertSame([$lease], array_column($search('rent forfeiture')->json('data.data'), 'id'), 'every word must appear');
        $this->assertSame([$lease], array_column($search('coven')->json('data.data'), 'id'), 'words match by their start');
        $this->assertSame([$memo], array_column($search('"rent review"')->json('data.data'), 'id'));
        $this->assertSame([], $search('"review rent"')->json('data.data'), 'a phrase must appear as written');
        $search('+merger -board (x) ~y');   // operators typed by the user are neutralised, not an SQL error
    }
}
