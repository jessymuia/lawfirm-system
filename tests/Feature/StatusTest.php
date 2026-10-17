<?php

namespace Tests\Feature;

use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\Status\Health;
use App\Status\Incidents;
use App\Status\StatusException;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** PLAT-12: health endpoints, probe history, measured availability, the public status page and incident handling. */
class StatusTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const HOST = 'http://admin.platform.test';

    private string $originalDefault;

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        Cache::flush();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function multi(): void
    {
        $this->dir = sys_get_temp_dir().'/plat12-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'database.connections.tenant_tpl' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'tenant_tpl',
            'tenancy.mode' => 'multi', 'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test', 'tenancy.platform_mfa' => 'optional',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    private function health(): Health
    {
        return app(Health::class);
    }

    private function incidents(): Incidents
    {
        return app(Incidents::class);
    }

    private function store()
    {
        return $this->health()->store();
    }

    /** Insert $n probes for a component, one per minute, ending $endAgoMin minutes ago. */
    private function probes(string $component, string $status, int $n, int $endAgoMin = 0): void
    {
        $rows = [];
        for ($i = 0; $i < $n; $i++) {
            $rows[] = ['component' => $component, 'status' => $status, 'latency_ms' => 1, 'detail' => null, 'probed_at' => now()->subMinutes($endAgoMin + $i)];
        }
        $this->store()->table('health_probes')->insert($rows);
    }

    private function breakStorage(): void
    {
        config(['filesystems.disks.broken' => ['driver' => 'local', 'root' => '/proc/nope'], 'status.storage_disk' => 'broken']);
    }

    // ---- endpoints -------------------------------------------------------------------------------------------------

    public function test_liveness_touches_nothing(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->getJson('/health/live')->assertOk()->assertExactJson(['status' => 'ok'])->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame(0, $queries);
    }

    public function test_readiness_reports_critical_components_and_nothing_else(): void
    {
        $r = $this->getJson('/health/ready')->assertOk();
        $this->assertSame(['status', 'cell', 'components'], array_keys($r->json()));
        $this->assertSame(['database', 'cache', 'storage'], array_keys($r->json('components')));
        $this->assertSame('operational', $r->json('status'));
        $this->assertSame('default', $r->json('cell'));
    }

    public function test_readiness_answers_503_when_a_critical_component_is_down_and_leaks_no_detail(): void
    {
        $this->breakStorage();
        $r = $this->getJson('/health/ready')->assertStatus(503);
        $this->assertSame('down', $r->json('status'));
        $this->assertSame('down', $r->json('components.storage'));
        $this->assertStringNotContainsString('proc', $r->getContent());      // no path, no exception text
        $this->assertStringNotContainsString('nope', $r->getContent());
    }

    public function test_endpoints_are_throttled(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->getJson('/health/live')->assertOk();
        }
        $this->getJson('/health/live')->assertStatus(429);
    }

    // ---- checks and probes -----------------------------------------------------------------------------------------

    public function test_a_failing_database_is_down_with_a_fixed_phrase(): void
    {
        config(['database.connections.broken' => ['driver' => 'sqlite', 'database' => '/nonexistent-dir/x.sqlite', 'prefix' => '']]);
        DB::setDefaultConnection('broken');
        [$s, , $d] = $this->health()->check('database');
        DB::setDefaultConnection($this->originalDefault);
        $this->assertSame(['down', 'check failed'], [$s, $d]);
    }

    public function test_probe_records_every_component_and_the_command_fails_when_one_is_down(): void
    {
        $this->assertSame(0, Artisan::call('health:probe'));
        $this->assertEqualsCanonicalizing(['database', 'cache', 'storage', 'queue'], $this->store()->table('health_probes')->pluck('component')->all());
        $this->assertSame(['operational'], $this->store()->table('health_probes')->pluck('status')->unique()->values()->all());

        $this->breakStorage();
        $this->assertSame(1, Artisan::call('health:probe'));
        $this->assertSame('down', $this->store()->table('health_probes')->where('component', 'storage')->orderByDesc('id')->value('status'));
        $this->assertSame('check failed', $this->store()->table('health_probes')->where('component', 'storage')->orderByDesc('id')->value('detail'));
    }

    public function test_silent_write_failures_are_caught_by_reading_back(): void
    {
        config(['cache.default' => 'null']);                                   // accepts writes, remembers nothing
        $this->assertSame(['down', 'read-back mismatch'], [$this->health()->check('cache')[0], $this->health()->check('cache')[2]]);
        config(['cache.default' => 'array']);
        $this->assertSame('operational', $this->health()->check('cache')[0]);

        // A disk that accepts writes without error but keeps nothing (a full or read-only mount behind a non-throwing driver).
        // Built by hand rather than with 'read-only' => true, which needs the optional league/flysystem-read-only package.
        $blackhole = \Mockery::mock(FilesystemAdapter::class);
        $blackhole->shouldReceive('put')->andReturn(false);
        $blackhole->shouldReceive('get')->andReturn(null);
        $blackhole->shouldReceive('delete')->andReturn(false);
        Storage::set('blackhole', $blackhole);
        config(['status.storage_disk' => 'blackhole']);
        $this->assertSame(['down', 'read-back mismatch'], [$this->health()->check('storage')[0], $this->health()->check('storage')[2]]);
    }

    public function test_the_probe_is_scheduled_every_minute(): void
    {
        $e = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'health:probe'));
        $this->assertNotNull($e, 'health:probe is not scheduled');
        $this->assertSame('* * * * *', $e->expression);
        $this->assertTrue($e->withoutOverlapping);
    }

    public function test_queue_backlog_degrades_and_inline_queues_are_reported_as_such(): void
    {
        [$s, , $d] = $this->health()->check('queue');
        $this->assertSame(['operational', 'runs inline'], [$s, $d]);

        config(['queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'status.queue_backlog_degraded' => 2]);
        $row = fn () => ['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => time(), 'created_at' => time()];
        DB::table('jobs')->insert([$row(), $row()]);
        $this->assertSame('operational', $this->health()->check('queue')[0]);   // at the threshold: fine
        DB::table('jobs')->insert([$row()]);
        $this->assertSame(['degraded', 'backlog'], [$this->health()->check('queue')[0], $this->health()->check('queue')[2]]);
    }

    public function test_the_probe_history_is_pruned(): void
    {
        $this->store()->table('health_probes')->insert([
            ['component' => 'database', 'status' => 'operational', 'latency_ms' => 1, 'probed_at' => now()->subDays(121)],
            ['component' => 'database', 'status' => 'operational', 'latency_ms' => 1, 'probed_at' => now()->subDays(119)],
        ]);
        $this->assertSame(1, $this->health()->prune());
        $this->assertSame(1, $this->store()->table('health_probes')->count());
    }

    public function test_current_state_goes_unknown_when_stale_and_a_silent_scheduler_is_down(): void
    {
        $c = $this->health()->current();
        $this->assertSame('down', $c['scheduler']['status']);                          // nothing ever probed
        $this->assertSame('unknown', $c['database']['status']);

        $this->probes('database', 'operational', 1);
        $this->probes('cache', 'degraded', 1, 10);                                      // old
        $c = $this->health()->current();
        $this->assertSame('operational', $c['database']['status']);
        $this->assertSame('operational', $c['scheduler']['status']);
        $this->assertSame('unknown', $c['cache']['status']);                            // stale measurements are not shown as current
        $this->assertSame('no recent measurement', $c['cache']['detail']);

        $this->store()->table('health_probes')->update(['probed_at' => now()->subMinutes(4)]);
        $this->assertSame('down', $this->health()->current()['scheduler']['status']);   // 3-minute staleness limit
    }

    public function test_overall_status_rules(): void
    {
        $h = $this->health();
        $this->assertSame('down', $h->overall(['database' => 'down', 'queue' => 'operational']));
        $this->assertSame('degraded', $h->overall(['database' => 'operational', 'queue' => 'down']));     // non-critical down = degraded
        $this->assertSame('degraded', $h->overall(['database' => 'operational', 'queue' => 'degraded']));
        $this->assertSame('operational', $h->overall(['database' => 'operational', 'queue' => 'unknown']));
        $this->assertSame('unknown', $h->overall(['queue' => 'unknown']));
    }

    // ---- availability ----------------------------------------------------------------------------------------------

    public function test_availability_is_measured_from_probes(): void
    {
        $this->probes('database', 'operational', 96, 4);
        $this->probes('database', 'down', 2, 2);
        $this->probes('database', 'degraded', 2, 0);                                    // degraded still counts as available
        $this->probes('database', 'unknown', 5, 200);                                   // not measured at all
        $a = $this->health()->availability(1)['database'];
        $this->assertSame(98.0, $a['availability']);
        $this->assertSame(100, $a['measured_minutes']);
        $this->assertSame(2, $a['down_minutes']);
        $this->assertSame(2, $a['degraded_minutes']);
        $this->assertNull($this->health()->availability(1)['cache']['availability']);   // nothing measured is null, never 100%
    }

    public function test_coverage_exposes_gaps_instead_of_hiding_them(): void
    {
        $this->probes('database', 'operational', 50, 0);
        $this->probes('database', 'operational', 50, 400);                              // a long silent gap between
        $a = $this->health()->availability(1)['database'];
        $this->assertSame(100.0, $a['availability']);                                  // the gap is neither available nor down ...
        $this->assertEqualsWithDelta(100 / 450 * 100, $a['coverage'], 1.0);            // ... but it is plainly visible: 100 probes of ~450 expected minutes
    }

    public function test_maintenance_windows_are_excluded_per_component_without_double_counting(): void
    {
        // probes and windows are both placed relative to now(); a second ticking by between them moves the probe at -30
        // minutes outside the window (seen on the slower MySQL run), so the clock stands still here
        $this->freezeSecond();
        $this->probes('database', 'operational', 80, 20);
        $this->probes('database', 'down', 20, 0);                                       // last 20 minutes down
        $this->probes('cache', 'operational', 80, 20);
        $this->probes('cache', 'down', 20, 0);
        $this->assertSame(80.0, $this->health()->availability(1)['database']['availability']);

        // two overlapping windows over the outage, only on the database
        $this->incidents()->open(['title' => 'Upgrade', 'impact' => 'maintenance', 'message' => 'x', 'components' => ['database'], 'starts_at' => now()->subMinutes(30)->toDateTimeString(), 'ends_at' => now()->addMinutes(5)->toDateTimeString()]);
        $this->incidents()->open(['title' => 'Upgrade 2', 'impact' => 'maintenance', 'message' => 'x', 'components' => ['database'], 'starts_at' => now()->subMinutes(15)->toDateTimeString(), 'ends_at' => now()->addMinutes(5)->toDateTimeString()]);
        $a = $this->health()->availability(1);
        $this->assertSame(100.0, $a['database']['availability']);
        $this->assertSame(0, $a['database']['down_minutes']);
        $this->assertSame(31, $a['database']['maintenance_minutes_excluded']);         // the 31 probes in [-30,0] counted once, not again for the overlapping window
        $this->assertSame(80.0, $a['cache']['availability']);                           // window did not name the cache

        $this->incidents()->open(['title' => 'All', 'impact' => 'maintenance', 'message' => 'x', 'starts_at' => now()->subMinutes(30)->toDateTimeString(), 'ends_at' => now()->addMinutes(5)->toDateTimeString()]);
        $this->assertSame(100.0, $this->health()->availability(1)['cache']['availability']);   // no components named = all
    }

    public function test_only_maintenance_and_never_ordinary_incidents_excuse_downtime(): void
    {
        $this->probes('database', 'operational', 80, 20);
        $this->probes('database', 'down', 20, 0);
        $this->incidents()->open(['title' => 'Outage', 'impact' => 'major', 'message' => 'We are looking into it.', 'components' => ['database']]);
        $this->assertSame(80.0, $this->health()->availability(1)['database']['availability']);
    }

    public function test_windows_are_capped_and_old_probes_fall_out(): void
    {
        $this->store()->table('health_probes')->insert(['component' => 'database', 'status' => 'down', 'latency_ms' => 0, 'probed_at' => now()->subDays(40)]);
        $this->probes('database', 'operational', 5);
        $this->assertSame(100.0, $this->health()->availability(30)['database']['availability']);
        $this->assertLessThan(100.0, $this->health()->availability(60)['database']['availability']);
        config(['status.max_window_days' => 10]);
        $this->assertSame(100.0, $this->health()->availability(9999)['database']['availability']);   // clamped to the cap
    }

    // ---- incidents -------------------------------------------------------------------------------------------------

    public function test_incident_lifecycle_and_rules(): void
    {
        $i = $this->incidents()->open(['title' => '  Slow searches ', 'impact' => 'minor', 'message' => 'Investigating slow searches.', 'components' => ['database']]);
        $this->assertSame(['Slow searches', 'investigating'], [$i->title, $i->status]);
        $this->assertCount(1, $i->updates);

        $i = $this->incidents()->update($i->id, 'identified', 'Found the cause.');
        $i = $this->incidents()->update($i->id, 'resolved', 'Fixed.');
        $this->assertNotNull($i->resolved_at);
        $this->assertSame(['investigating', 'identified', 'resolved'], array_map(fn ($u) => $u->status, $i->updates));

        try {
            $this->incidents()->update($i->id, 'investigating', 'again');
            $this->fail('reopened');
        } catch (StatusException $e) {
            $this->assertSame(409, $e->getCode());
        }
    }

    public function test_invalid_incidents_are_refused(): void
    {
        $bad = [
            ['impact' => 'catastrophic'],                                                  // unknown impact
            ['components' => ['nonsense']],                                                // unknown component
            ['status' => 'resolved'],                                                      // cannot be born closed
            ['status' => 'scheduled'],                                                     // only maintenance is scheduled
            ['starts_at' => now()->addDay()->toDateTimeString()],                          // an outage cannot start in the future
            ['message' => '   '],                                                          // a message is required
            ['impact' => 'maintenance'],                                                   // maintenance needs an end
            ['impact' => 'maintenance', 'starts_at' => now()->addHour()->toDateTimeString(), 'ends_at' => now()->toDateTimeString()],
        ];
        foreach ($bad as $b) {
            try {
                $this->incidents()->open($b + ['title' => 't', 'impact' => 'minor', 'message' => 'm']);
                $this->fail('accepted '.json_encode($b));
            } catch (StatusException $e) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, $this->store()->table('status_incidents')->count());
        $this->assertSame(0, $this->store()->table('status_incident_updates')->count());   // atomic: no orphan update
    }

    public function test_the_command_posts_incidents(): void
    {
        $this->assertSame(0, Artisan::call('status:incident', ['action' => 'open', 'message' => 'Looking into it', '--title' => 'Delays', '--impact' => 'minor', '--components' => ['queue']]));
        $id = (int) $this->store()->table('status_incidents')->max('id');   // auto-increment ids are not reset between tests on MySQL
        $this->assertSame(0, Artisan::call('status:incident', ['action' => 'update', 'message' => 'Done', '--id' => $id, '--status' => 'resolved']));
        $this->assertSame(1, Artisan::call('status:incident', ['action' => 'update', 'message' => 'Again', '--id' => $id, '--status' => 'monitoring']));   // closed
        $this->assertSame(1, Artisan::call('status:incident', ['action' => 'open', 'message' => 'm', '--title' => 't', '--impact' => 'bogus']));
    }

    // ---- public page -----------------------------------------------------------------------------------------------

    public function test_the_public_page_and_json_show_status_availability_and_incidents(): void
    {
        foreach (['database', 'cache', 'storage', 'queue'] as $c) {
            $this->probes($c, 'operational', 10);
        }
        $this->probes('database', 'down', 1, 30);
        $this->incidents()->open(['title' => 'Planned work', 'impact' => 'maintenance', 'message' => 'Upgrading.', 'starts_at' => now()->addDay()->toDateTimeString(), 'ends_at' => now()->addDays(2)->toDateTimeString()]);
        $j = $this->getJson('/status.json')->assertOk()->assertHeader('Cache-Control', 'max-age=30, public');
        $this->assertSame('operational', $j->json('status'));
        $db = collect($j->json('components'))->firstWhere('id', 'database');
        $this->assertSame(['Database', 'operational'], [$db['name'], $db['status']]);
        $this->assertEqualsWithDelta(10 / 11 * 100, $db['availability_30d'], 0.001);
        $this->assertSame('Planned work', $j->json('incidents.0.title'));
        $this->assertStringNotContainsString('staff', $j->getContent());

        $page = $this->get('/status')->assertOk();
        $this->assertStringContainsString('All systems operational', $page->getContent());
        $this->assertStringContainsString('Planned work', $page->getContent());
        $this->assertStringContainsString("default-src 'none'", $page->headers->get('Content-Security-Policy'));
    }

    public function test_public_text_is_escaped(): void
    {
        $this->incidents()->open(['title' => '<script>alert(1)</script>', 'impact' => 'minor', 'message' => '<img src=x onerror=alert(2)>']);
        $html = $this->get('/status')->getContent();
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_the_page_says_so_when_nothing_is_measured_or_the_scheduler_is_silent(): void
    {
        $j = $this->getJson('/status.json')->json();
        $this->assertSame('unknown', collect($j['components'])->firstWhere('id', 'database')['status']);
        $this->assertSame('down', collect($j['components'])->firstWhere('id', 'scheduler')['status']);
        $this->assertNull(collect($j['components'])->firstWhere('id', 'database')['availability_30d']);       // not a flattering 100%
    }

    public function test_open_incidents_come_first_and_old_resolved_ones_drop_off(): void
    {
        $old = $this->incidents()->open(['title' => 'Old', 'impact' => 'minor', 'message' => 'm']);
        $this->incidents()->update($old->id, 'resolved', 'done');
        $this->store()->table('status_incidents')->where('id', $old->id)->update(['started_at' => now()->subDays(100), 'resolved_at' => now()->subDays(99)]);
        $recent = $this->incidents()->open(['title' => 'Recent', 'impact' => 'minor', 'message' => 'm']);
        $this->incidents()->update($recent->id, 'resolved', 'done');
        $open = $this->incidents()->open(['title' => 'Open', 'impact' => 'major', 'message' => 'm']);
        $this->assertSame(['Open', 'Recent'], array_map(fn ($i) => $i->title, $this->incidents()->recent(90)));
    }

    // ---- multi-tenant cell -----------------------------------------------------------------------------------------

    public function test_in_multi_mode_health_answers_on_any_host_and_uses_the_control_plane(): void
    {
        $this->multi();
        $this->getJson('http://stranger.example/health/live')->assertOk();
        $this->getJson('http://stranger.example/health/ready')->assertOk()->assertJsonPath('status', 'operational');
        $this->getJson('http://stranger.example/status.json')->assertOk();
        $this->get('http://stranger.example/status')->assertOk();
        $this->assertSame('control', $this->store()->getName());
    }

    public function test_the_carve_out_is_exactly_those_paths(): void
    {
        $this->multi();
        $this->getJson('http://stranger.example/api/v1/clients')->assertNotFound();           // unknown host still refused
        $this->getJson('http://stranger.example/status/extra')->assertNotFound();
        $this->getJson('http://stranger.example/health/other')->assertNotFound();
        $this->getJson('http://stranger.example/health')->assertNotFound();
    }

    public function test_firm_databases_are_sampled_in_multi_mode(): void
    {
        $this->multi();
        $make = fn ($slug, $path) => Tenant::create(['slug' => $slug, 'name' => $slug, 'status' => 'active', 'plan' => 'trial', 'database' => $path, 'schema_version' => 'x', 'migration_status' => 'ok']);
        $this->assertSame(['operational', 'no firms'], [$this->health()->check('tenant_databases')[0], $this->health()->check('tenant_databases')[2]]);

        touch("{$this->dir}/good.sqlite");
        $make('good', "{$this->dir}/good.sqlite");
        $this->assertSame('operational', $this->health()->check('tenant_databases')[0]);
        $make('bad', '/nonexistent-dir/bad.sqlite');
        [$s, , $d] = $this->health()->check('tenant_databases');
        $this->assertSame(['degraded', '1/2 unreachable'], [$s, $d]);
        $this->assertStringNotContainsString('bad', $d);                                       // the public record never names a firm

        Tenant::where('slug', 'good')->update(['database' => '/nonexistent-dir/good.sqlite']);
        $this->assertSame('down', $this->health()->check('tenant_databases')[0]);

        config(['status.tenant_sample' => 1]);                                                 // the sample is bounded
        $this->assertMatchesRegularExpression('#^1/1 unreachable$#', $this->health()->check('tenant_databases')[2]);
    }

    // ---- platform console ------------------------------------------------------------------------------------------

    private function staffToken(string $role): string
    {
        $s = PlatformStaff::create(['name' => $role, 'email' => "{$role}@platform.test", 'password' => Hash::make('correct-horse-battery'), 'role' => $role]);

        return $this->postJson(self::HOST.'/api/platform/login', ['email' => $s->email, 'password' => 'correct-horse-battery'])->assertOk()->json('token');
    }

    public function test_console_endpoints_need_staff_and_the_right_role_and_changes_are_audited(): void
    {
        $this->multi();
        $viewer = $this->staffToken('viewer');
        $op = $this->staffToken('operator');
        app('auth')->forgetGuards();

        $this->getJson(self::HOST.'/api/platform/health')->assertUnauthorized();
        $this->getJson(self::HOST.'/api/platform/sla')->assertUnauthorized();
        $this->getJson('http://stranger.example/api/platform/health')->assertNotFound();      // console exists only on the platform host

        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/health')->assertOk()->assertJsonPath('data.cell', 'default');
        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/sla?days=7')->assertOk()->assertJsonPath('data.days', 7);
        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/sla?days=999')->assertStatus(422);
        $body = ['title' => 'Slow', 'impact' => 'minor', 'message' => 'Investigating.', 'components' => ['database']];
        $this->withToken($viewer)->postJson(self::HOST.'/api/platform/incidents', $body)->assertForbidden();

        $r = $this->withToken($op)->postJson(self::HOST.'/api/platform/incidents', $body)->assertCreated();
        $id = $r->json('data.id');
        $this->withToken($op)->postJson(self::HOST."/api/platform/incidents/{$id}/updates", ['status' => 'resolved', 'message' => 'Fixed.'])->assertOk()->assertJsonPath('data.status', 'resolved');
        $this->withToken($op)->postJson(self::HOST."/api/platform/incidents/{$id}/updates", ['status' => 'monitoring', 'message' => 'again'])->assertStatus(409);
        $this->withToken($op)->postJson(self::HOST.'/api/platform/incidents', ['impact' => 'minor'] + $body)->assertCreated();
        $this->withToken($op)->postJson(self::HOST.'/api/platform/incidents', ['components' => ['bogus']] + $body)->assertStatus(422);
        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/incidents')->assertOk()->assertJsonCount(2, 'data');

        $actions = PlatformAudit::orderBy('id')->get()->map(fn ($a) => [$a->action, $a->result])->all();
        $this->assertContains(['status.incident.open', 'ok'], $actions);
        $this->assertContains(['status.incident.update', 'ok'], $actions);
        $this->assertContains(['status.incident.update', 'failed'], $actions);                // refusals are recorded too
    }

    public function test_the_console_health_view_names_unreachable_firms_but_the_public_one_never_does(): void
    {
        $this->multi();
        Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'status' => 'active', 'plan' => 'trial', 'database' => '/nonexistent-dir/a.sqlite', 'schema_version' => 'x', 'migration_status' => 'ok']);
        $viewer = $this->staffToken('viewer');
        app('auth')->forgetGuards();
        $this->withToken($viewer)->getJson(self::HOST.'/api/platform/health')->assertOk()->assertJsonPath('data.firms.0', ['slug' => 'acme', 'database_reachable' => false]);

        Artisan::call('health:probe');
        foreach (['/status.json', '/status', '/health/ready'] as $p) {
            $this->assertStringNotContainsStringIgnoringCase('acme', $this->get('http://stranger.example'.$p)->getContent());
        }
    }
}
