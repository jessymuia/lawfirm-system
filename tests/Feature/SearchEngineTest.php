<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\ExtractDocumentText;
use App\Matters\MatterAccess;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\User;
use App\Search\MeilisearchEngine;
use App\Search\SearchEngine;
use App\Search\SearchIndex;
use App\Search\SearchPurgeStep;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-19: global search through a search engine, one set of indexes per firm, permissions applied by the engine. */
class SearchEngineTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $lawyer;

    private string $firm;

    /** @var list<array> what the engine answers to searches, per index suffix */
    private array $hits = [];

    private bool $down = false;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([ExtractDocumentText::class]);
        config(['search.engine' => 'meilisearch', 'search.meilisearch.host' => 'http://search.test:7700', 'search.meilisearch.key' => 'master-key',
            'search.index_prefix' => 'lf_']);
        $this->app->forgetInstance(SearchEngine::class);
        $this->firm = app(TenantContext::class)->key();
        Http::fake(function (Request $r) {
            if ($this->down) {
                return Http::response(['message' => 'down'], 503);
            }
            if (str_ends_with($r->url(), '/search')) {
                $entity = substr(basename(dirname($r->url())), strlen('lf_'.$this->firm.'_'));

                return Http::response(['hits' => $this->hits[$entity] ?? []]);
            }

            return Http::response(['taskUid' => 1], 202);
        });
        $this->lawyer = $this->userWithPermissions([P::LIST_CASES, P::LIST_CLIENTS, P::LIST_DOCUMENTS]);
    }

    private function sent(string $path): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $path))->map(fn ($p) => $p[0])->all();
    }

    public function test_records_are_indexed_in_the_firms_own_index_with_who_may_see_them(): void
    {
        $client = Client::factory()->create(['name' => 'Wanjiru Holdings']);
        $matter = Cases::factory()->create(['client_id' => $client->id, 'assigned_to' => $this->lawyer->id, 'case_number' => 'HCCC 12/2026']);
        DB::table('attorneys')->insert(['case_id' => $matter->id, 'advocate_id' => $colleague = User::factory()->create()->id, 'role' => 'assisting', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('search:reindex');

        $index = "lf_{$this->firm}_cases";
        $put = collect($this->sent("/indexes/{$index}/documents?primaryKey=id"))->last();
        $this->assertNotNull($put, 'the firm\'s own index');
        $this->assertSame('Bearer master-key', $put->header('Authorization')[0]);
        $record = collect($put->data())->firstWhere('id', $matter->id);
        $this->assertSame(['HCCC 12/2026', 'Wanjiru Holdings', $client->id], [$record['case_number'], $record['client'], $record['client_id']]);
        $this->assertEqualsCanonicalizing(array_values(array_unique(array_filter([$this->lawyer->id, $matter->created_by, $colleague]))), $record['access_users']);

        $settings = collect($this->sent("/indexes/{$index}/settings"))->last();
        $this->assertSame(MeilisearchEngine::SETTINGS['cases'], $settings->data());

        // a change is sent as it happens (queued; the test queue runs it at once)
        $client->update(['name' => 'Wanjiru Holdings Ltd']);
        $this->assertSame('Wanjiru Holdings Ltd', collect($this->sent("/indexes/{$index}/documents?"))->flatMap->data()->where('id', $matter->id)->last()['client']);

        $matter->delete();
        $this->assertSame([$matter->id], collect($this->sent("/indexes/{$index}/documents/delete-batch"))->last()->data());
    }

    public function test_only_current_versions_are_indexed_with_their_text(): void
    {
        $matter = Cases::factory()->create();
        $v1 = Document::create(['title' => 'Plaint', 'case_id' => $matter->id, 'version' => 1, 'is_current' => false, 'filename' => 'p.pdf', 'filepath' => 'x', 'full_path' => 'x', 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 1, 'extension' => 'pdf']);
        $v2 = Document::create(['title' => 'Plaint', 'case_id' => $matter->id, 'version' => 2, 'is_current' => true, 'filename' => 'p.pdf', 'filepath' => 'y', 'full_path' => 'y', 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 1, 'extension' => 'pdf']);
        DB::table('document_texts')->insert(['document_id' => $v2->id, 'state' => 'done', 'content' => str_repeat('negligence ', 5000), 'created_at' => now(), 'updated_at' => now()]);
        config(['search.document_text_chars' => 100]);

        $records = app(SearchIndex::class)->records('documents', [$v1->id, $v2->id]);
        $this->assertSame([$v2->id], $records->keys()->all());
        $this->assertSame(100, mb_strlen($records[$v2->id]['content']));
        $this->assertSame([$matter->case_number, $matter->client_id], [$records[$v2->id]['case_number'], $records[$v2->id]['client_id']]);
    }

    public function test_the_engine_filters_by_walls_and_matter_access_while_it_searches(): void
    {
        $this->actingAs($this->lawyer)->getJson('/api/v1/search?q=wanjiru')->assertOk();
        foreach (['cases', 'clients', 'documents'] as $entity) {
            $body = collect($this->sent("/indexes/lf_{$this->firm}_{$entity}/search"))->last()->data();
            $this->assertSame('wanjiru', $body['q']);
            $this->assertArrayNotHasKey('filter', $body, "{$entity}: firm-wide access, no walls: nothing to filter");
        }

        // restricted access, a grant, and walls
        config(['matters.access' => 'restricted']);
        $walledClient = Client::factory()->create();
        $walledMatter = Cases::factory()->create();
        $granted = Cases::factory()->create();
        foreach ([['client_id' => $walledClient->id], ['case_id' => $walledMatter->id]] as $wall) {
            DB::table('ethical_screens')->insert($wall + ['user_id' => $this->lawyer->id, 'reason' => 'acts for the other side', 'created_at' => now(), 'updated_at' => now()]);
        }
        MatterAccess::walls()->flush();
        DB::table('matter_access_grants')->insert(['case_id' => $granted->id, 'user_id' => $this->lawyer->id, 'reason' => 'cover', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->lawyer)->getJson('/api/v1/search?q=wanjiru')->assertOk();
        $u = $this->lawyer->id;
        $this->assertSame("id NOT IN [{$walledClient->id}]", collect($this->sent("lf_{$this->firm}_clients/search"))->last()->data()['filter']);
        $this->assertSame("client_id NOT IN [{$walledClient->id}] AND id NOT IN [{$walledMatter->id}] AND (access_users = {$u} OR id IN [{$granted->id}])",
            collect($this->sent("lf_{$this->firm}_cases/search"))->last()->data()['filter']);
        $this->assertSame("client_id NOT IN [{$walledClient->id}] AND case_id NOT IN [{$walledMatter->id}] AND (access_users = {$u} OR case_id IN [{$granted->id}])",
            collect($this->sent("lf_{$this->firm}_documents/search"))->last()->data()['filter']);

        // someone who may see every matter is not restricted (walls still apply)
        $partner = $this->userWithPermissions([P::LIST_CASES, P::ACCESS_ALL_MATTERS]);
        $this->actingAs($partner)->getJson('/api/v1/search?q=wanjiru')->assertOk();
        $this->assertArrayNotHasKey('filter', collect($this->sent("lf_{$this->firm}_cases/search"))->last()->data());
    }

    public function test_results_follow_the_engines_ranking_and_a_stale_entry_is_never_shown(): void
    {
        $best = Cases::factory()->create(['case_number' => 'BEST']);
        $second = Cases::factory()->create(['case_number' => 'SECOND']);
        $gone = Cases::factory()->create(['case_number' => 'GONE']);
        $gone->delete();   // the engine has not caught up yet
        $doc = Document::create(['title' => 'Witness statement', 'case_id' => $best->id, 'version' => 1, 'is_current' => true, 'filename' => 'w.pdf', 'filepath' => 'w', 'full_path' => 'w', 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => 1, 'extension' => 'pdf']);
        $this->hits = ['cases' => [['id' => $second->id], ['id' => $gone->id], ['id' => $best->id]],
            'documents' => [['id' => $doc->id, '_formatted' => ['content' => '…the driver was negligent…']]]];

        $d = $this->actingAs($this->lawyer)->getJson('/api/v1/search?q=negligent')->assertOk()->json('data');
        $this->assertSame(['SECOND', 'BEST'], array_column($d['cases'], 'title'));
        $this->assertSame('BEST · …the driver was negligent…', $d['documents'][0]['subtitle']);
    }

    public function test_if_the_engine_fails_the_search_still_answers_from_the_database(): void
    {
        Cases::factory()->create(['case_number' => 'ELC 4/2026']);
        $this->down = true;

        $d = $this->actingAs($this->lawyer)->getJson('/api/v1/search?q=ELC 4')->assertOk()->json('data');
        $this->assertSame(['ELC 4/2026'], array_column($d['cases'], 'title'));
    }

    public function test_without_an_engine_nothing_is_sent_anywhere(): void
    {
        config(['search.engine' => 'database']);
        $this->app->forgetInstance(SearchEngine::class);
        Cases::factory()->create(['case_number' => 'ELC 5/2026']);
        $this->actingAs($this->lawyer)->getJson('/api/v1/search?q=ELC 5')->assertOk()->assertJsonPath('data.cases.0.title', 'ELC 5/2026');
        Artisan::call('search:reindex');
        $this->assertStringContainsString('nothing to index', Artisan::output());
        Http::assertNothingSent();
    }

    public function test_deleting_a_firm_deletes_its_indexes(): void
    {
        $outcome = app(SearchPurgeStep::class)->purge(new Tenant(['slug' => 'acme']));
        $this->assertSame('dropped', $outcome);
        $this->assertEqualsCanonicalizing(['lf_acme_cases', 'lf_acme_clients', 'lf_acme_documents'],
            Http::recorded(fn (Request $r) => $r->method() === 'DELETE')->map(fn ($p) => basename($p[0]->url()))->all());
    }
}
