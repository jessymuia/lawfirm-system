<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Search\SearchEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * ENG-19 against a real Meilisearch (MEILISEARCH_TEST_HOST, default http://127.0.0.1:7700, key MEILISEARCH_TEST_KEY);
 * skipped where none is reachable. Proves the filter syntax and ranking the engine actually applies.
 */
class MeilisearchIntegrationTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private string $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = (string) (getenv('MEILISEARCH_TEST_HOST') ?: 'http://127.0.0.1:7700');
        try {
            $up = Http::timeout(2)->get($this->host.'/health')->successful();
        } catch (\Throwable) {
            $up = false;
        }
        if (! $up) {
            $this->markTestSkipped("No Meilisearch at {$this->host}.");
        }
        config(['search.engine' => 'meilisearch', 'search.meilisearch.host' => $this->host,
            'search.meilisearch.key' => getenv('MEILISEARCH_TEST_KEY') ?: 'test-master-key-1234567890', 'search.index_prefix' => 'test'.getmypid().'_']);
        $this->app->forgetInstance(SearchEngine::class);
    }

    protected function tearDown(): void
    {
        if (config('search.engine') === 'meilisearch') {
            app(SearchEngine::class)->dropFirm(app(\App\Tenancy\TenantContext::class)->key());
        }
        parent::tearDown();
    }

    /** Meilisearch applies writes asynchronously. */
    private function settle(): void
    {
        $key = config('search.meilisearch.key');
        for ($i = 0; $i < 100; $i++) {
            $pending = Http::withToken($key)->get($this->host.'/tasks', ['statuses' => 'enqueued,processing'])->json('total');
            if ((int) $pending === 0) {
                return;
            }
            usleep(100_000);
        }
        $this->fail('Meilisearch did not finish indexing.');
    }

    public function test_ranked_typo_tolerant_and_filtered_by_access(): void
    {
        $me = $this->userWithPermissions([P::LIST_CASES, P::LIST_CLIENTS]);
        $other = User::factory()->create();
        $mine = Cases::factory()->create(['case_number' => 'HCCC 1/2026', 'description' => 'Negligence claim after a road accident', 'assigned_to' => $me->id,
            'client_id' => Client::factory()->create(['name' => 'Wanjiru Kamau'])->id]);
        $theirs = Cases::factory()->create(['case_number' => 'HCCC 2/2026', 'description' => 'Negligence in surgery on Mr Kamau', 'assigned_to' => $other->id, 'created_by' => $other->id,
            'opposing_party' => 'Mercy Hospital', 'client_id' => Client::factory()->create(['name' => 'Otieno Odhiambo'])->id]);
        $mine->update(['opposing_party' => 'Matatu Sacco']);
        Cases::factory()->create(['case_number' => 'HCCC 3/2026', 'description' => 'Lease dispute', 'opposing_party' => 'Kamau Motors',
            'client_id' => Client::factory()->create(['name' => 'Akinyi Achieng'])->id]);
        $walled = Client::factory()->create(['name' => 'Wanjiru Traders']);
        Artisan::call('search:reindex');
        $this->settle();

        // typo-tolerant ("Kamua"), and ranked by where the word is: the client's name, then the other side, then the description
        $d = $this->actingAs($me)->getJson('/api/v1/search?q=kamua')->assertOk()->json('data');
        $this->assertSame(['HCCC 1/2026', 'HCCC 3/2026', 'HCCC 2/2026'], array_column($d['cases'], 'title'));
        $this->assertSame(['Wanjiru Kamau'], array_column($d['clients'], 'title'));

        // restricted access: the engine itself returns only my matter
        config(['matters.access' => 'restricted']);
        $this->assertSame(['HCCC 1/2026'], array_column($this->actingAs($me)->getJson('/api/v1/search?q=negligence')->json('data.cases'), 'title'));

        // a grant opens the other one; a wall on a client hides it
        DB::table('matter_access_grants')->insert(['case_id' => $theirs->id, 'user_id' => $me->id, 'reason' => 'cover', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ethical_screens')->insert(['user_id' => $me->id, 'client_id' => $walled->id, 'reason' => 'conflict', 'created_at' => now(), 'updated_at' => now()]);
        \App\Matters\MatterAccess::walls()->flush();
        $this->assertEqualsCanonicalizing(['HCCC 1/2026', 'HCCC 2/2026'], array_column($this->actingAs($me)->getJson('/api/v1/search?q=negligence')->json('data.cases'), 'title'));
        $this->assertNotContains('Wanjiru Traders', array_column($this->actingAs($me)->getJson('/api/v1/search?q=wanjiru')->json('data.clients'), 'title'));

        // an edit reaches the index
        $mine->update(['description' => 'Defamation']);
        $this->settle();
        $this->assertSame([], array_column($this->actingAs($me)->getJson('/api/v1/search?q=accident')->json('data.cases'), 'title'));
    }
}
