<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\BackfillPublicIds;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Hearing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** DATA-9: ULID public ids on records that leave the system. */
class PublicIdTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    public function test_new_records_get_a_ulid_that_never_changes(): void
    {
        $client = Client::factory()->create();
        $case = Cases::factory()->create(['client_id' => $client->id]);
        $hearing = Hearing::factory()->create(['case_id' => $case->id]);

        foreach ([$client, $case, $hearing] as $m) {
            $this->assertTrue($m::isPublicId($m->public_id), $m::class);
        }
        $this->assertNotSame($client->public_id, Client::factory()->create()->public_id);

        $this->expectException(\LogicException::class);
        $client->forceFill(['public_id' => Client::newPublicId()])->save();
    }

    public function test_ids_sort_by_creation_time(): void
    {
        $a = Client::newPublicId();
        usleep(2000);
        $b = Client::newPublicId();

        $this->assertLessThan(0, strcmp($a, $b));
    }

    public function test_backfill_fills_only_missing_ids_without_audit_rows_or_touching_updated_at_and_is_idempotent(): void
    {
        $clients = Client::factory()->count(3)->create();
        $case = Cases::factory()->create(['client_id' => $clients[0]->id]);
        $kept = $clients[2]->public_id;
        DB::table('clients')->whereIn('id', [$clients[0]->id, $clients[1]->id])->update(['public_id' => null, 'updated_at' => '2020-01-01 00:00:00']);
        DB::table('cases')->where('id', $case->id)->update(['public_id' => null]);
        $audits = DB::table('audits')->count();

        $filled = (new BackfillPublicIds(chunk: 1))->handle();

        $this->assertSame(['clients' => 2, 'cases' => 1, 'hearings' => 0], $filled);
        $this->assertSame(0, DB::table('clients')->whereNull('public_id')->count());
        $this->assertSame($kept, $clients[2]->fresh()->public_id, 'existing ids are never replaced');
        $this->assertSame('2020-01-01 00:00:00', (string) DB::table('clients')->where('id', $clients[0]->id)->value('updated_at'));
        $this->assertSame($audits, DB::table('audits')->count());
        $this->assertSame(['clients' => 0, 'cases' => 0, 'hearings' => 0], (new BackfillPublicIds)->handle());
    }

    public function test_a_row_that_predates_the_backfill_gets_its_id_on_first_use_and_keeps_it(): void
    {
        $client = Client::factory()->create();
        DB::table('clients')->where('id', $client->id)->update(['public_id' => null]);
        $client = Client::find($client->id);

        $id = $client->publicId();

        $this->assertTrue(Client::isPublicId($id));
        $this->assertSame($id, Client::find($client->id)->publicId());
    }

    public function test_upgrade_runs_the_backfill(): void
    {
        $client = Client::factory()->create();
        DB::table('clients')->where('id', $client->id)->update(['public_id' => null]);

        $this->assertSame(0, Artisan::call('app:upgrade', ['--skip-cache' => true]));

        $this->assertNotNull(DB::table('clients')->where('id', $client->id)->value('public_id'));
    }

    public function test_the_client_page_resolves_its_public_id_and_still_accepts_the_internal_id(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($this->userWithPermissions([P::LIST_CLIENTS]));

        $this->getJson('/api/v1/clients/'.$client->public_id)->assertOk()->assertJsonPath('data.id', $client->id)->assertJsonPath('data.public_id', $client->public_id);
        $this->getJson('/api/v1/clients/'.strtolower($client->public_id))->assertOk();
        $this->getJson('/api/v1/clients/'.$client->id)->assertOk();
        $this->getJson('/api/v1/clients/'.Client::newPublicId())->assertNotFound();

        $row = collect($this->getJson('/api/v1/clients')->json('data.data'))->firstWhere('id', $client->id);
        $this->assertSame($client->public_id, $row['public_id']);
    }

    public function test_lists_that_link_to_a_client_carry_its_public_id(): void
    {
        $case = Cases::factory()->create();
        $this->actingAs($this->adminUser());

        $row = collect($this->getJson('/api/v1/cases')->json('data.data'))->firstWhere('id', $case->id);

        $this->assertSame($case->client->public_id, $row['client_public_id']);
    }
}
