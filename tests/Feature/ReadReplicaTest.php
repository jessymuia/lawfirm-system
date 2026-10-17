<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Database\ReadReplica;
use App\Models\Client;
use App\Tenancy\CellRegistry;
use App\Tenancy\Models\Cell;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-23: reports and the dashboard read from a replica when there is a fit one, and say how far behind it is. */
class ReadReplicaTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private const REPORT = '/api/v1/reports/summary?from=2026-10-01&to=2026-10-31';

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->userWithPermissions([P::VIEW_DASHBOARD, P::VIEW_PARTNER_REPORTS, P::LIST_CLIENTS]));
    }

    /** A second connection to the same (in-memory) database stands in for the replica; its query log shows what went there. */
    private function replica(): Connection
    {
        config(['database.connections.replica_test' => config('database.connections.'.DB::getDefaultConnection()), 'reports.connection' => 'replica_test']);
        $c = DB::connection('replica_test');
        $c->setPdo(DB::connection()->getPdo());
        $c->enableQueryLog();

        return $c;
    }

    private function lag(int|false|null $seconds): void
    {
        $mock = Mockery::mock(ReadReplica::class)->makePartial()->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('measureLag')->andReturn($seconds);
        $this->app->instance(ReadReplica::class, $mock);
    }

    public function test_without_a_replica_everything_reads_from_the_primary(): void
    {
        $this->getJson('/api/v1/dashboard')->assertOk()->assertHeader('X-Data-Source', 'primary')->assertHeaderMissing('X-Data-Lag');
        $this->getJson(self::REPORT)->assertOk()->assertHeader('X-Data-Source', 'primary');
    }

    public function test_reports_and_the_dashboard_read_from_the_replica_and_the_default_comes_back(): void
    {
        Client::factory()->create();
        $replica = $this->replica();
        $default = DB::getDefaultConnection();

        $this->getJson('/api/v1/dashboard')->assertOk()->assertHeader('X-Data-Source', 'replica')->assertJsonPath('data.stats.total_clients', 1);
        $this->assertNotEmpty(array_filter($replica->getQueryLog(), fn ($q) => (bool) preg_match('/["`]clients["`]/', $q['query'])), 'the dashboard counted on the replica');
        $replica->flushQueryLog();

        $this->getJson(self::REPORT)->assertOk()->assertHeader('X-Data-Source', 'replica');
        $this->assertNotEmpty(array_filter($replica->getQueryLog(), fn ($q) => str_contains($q['query'], 'time_entries')), 'the report ran on the replica');
        $this->assertSame($default, DB::getDefaultConnection(), 'later work in the request writes to the primary');

        // a route that is not marked as a read never goes there
        $replica->flushQueryLog();
        $this->getJson('/api/v1/clients')->assertOk()->assertHeaderMissing('X-Data-Source');
        $this->assertSame([], $replica->getQueryLog());
    }

    public function test_lag_is_reported_and_a_replica_too_far_behind_or_stopped_is_skipped(): void
    {
        $this->replica();
        config(['database.replica.max_lag' => 300]);

        $this->lag(120);
        $this->getJson(self::REPORT)->assertOk()->assertHeader('X-Data-Source', 'replica')->assertHeader('X-Data-Lag', '120');

        $this->lag(900);
        $this->getJson(self::REPORT)->assertOk()->assertHeader('X-Data-Source', 'primary')->assertHeaderMissing('X-Data-Lag');

        $this->lag(false);   // replication stopped
        $this->getJson('/api/v1/dashboard')->assertOk()->assertHeader('X-Data-Source', 'primary');

        $this->lag(null);    // cannot tell: still used, without a figure
        $this->getJson('/api/v1/dashboard')->assertOk()->assertHeader('X-Data-Source', 'replica')->assertHeaderMissing('X-Data-Lag');
    }

    public function test_an_unreachable_replica_falls_back_to_the_primary(): void
    {
        config(['database.connections.replica_test' => ['driver' => 'sqlite', 'database' => '/nonexistent/'.uniqid().'/replica.sqlite', 'prefix' => ''],
            'reports.connection' => 'replica_test']);

        $this->getJson(self::REPORT)->assertOk()->assertHeader('X-Data-Source', 'primary');
    }

    public function test_where_the_replica_is(): void
    {
        $r = new ReadReplica;
        $this->assertNull($r->config(), 'nothing configured');

        config(['database.replica' => ['host' => 'replica.internal', 'port' => 3307, 'username' => null, 'password' => null, 'max_lag' => 300]]);
        $c = $r->config();
        $this->assertSame(['replica.internal', 3307], [$c['host'], $c['port']]);
        $this->assertSame(config('database.connections.'.DB::getDefaultConnection().'.database'), $c['database'], 'the same database, on the replica');

        // multi-firm: the firm's cell names its replica; a cell on its own server without one has none
        $tenants = Mockery::mock(TenantManager::class);
        $this->app->instance(TenantManager::class, $tenants);
        $cells = Mockery::mock(CellRegistry::class);
        $this->app->instance(CellRegistry::class, $cells);

        $tenants->shouldReceive('current')->andReturn(new Tenant(['cell' => 'eu-1']));
        $cells->shouldReceive('find')->with('eu-1')->andReturn(new Cell(['name' => 'eu-1', 'host' => 'db-eu-1', 'replica_host' => 'db-eu-1-ro', 'replica_port' => 3310]));
        $this->assertSame(['db-eu-1-ro', 3310], [$r->config()['host'], $r->config()['port']]);

        $cells2 = Mockery::mock(CellRegistry::class);
        $cells2->shouldReceive('find')->andReturn(new Cell(['name' => 'eu-1', 'host' => 'db-eu-1']));
        $this->app->instance(CellRegistry::class, $cells2);
        $this->assertNull($r->config(), "the environment's replica belongs to the template's server, not this cell's");

        $cells3 = Mockery::mock(CellRegistry::class);
        $cells3->shouldReceive('find')->andReturn(null);   // the template-only default cell
        $this->app->instance(CellRegistry::class, $cells3);
        $this->assertSame('replica.internal', $r->config()['host']);
    }

    public function test_the_read_only_routes_are_marked(): void
    {
        $marked = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && in_array('replica', $r->gatherMiddleware(), true))->map->uri()->sort()->values()->all();
        $this->assertSame(['api/v1/dashboard', 'api/v1/reports/profitability', 'api/v1/reports/realisation', 'api/v1/reports/receivables',
            'api/v1/reports/summary', 'api/v1/reports/utilisation', 'api/v1/reports/wip'], $marked);
    }
}
