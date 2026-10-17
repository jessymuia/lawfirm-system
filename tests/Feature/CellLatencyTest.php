<?php

namespace Tests\Feature;

use App\Tenancy\CellLatency;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/** TEN-4: the latency probe: percentiles, the command's table, a limit, and the proxy comparison. */
class CellLatencyTest extends TestCase
{
    public function test_percentiles(): void
    {
        $this->assertSame(['p50' => 5.0, 'p95' => 10.0, 'max' => 10.0, 'mean' => 5.5], CellLatency::stats([1, 2, 3, 4, 5, 6, 7, 8, 9, 10]));
        $this->assertSame(['p50' => 50.0, 'p95' => 95.0, 'max' => 100.0, 'mean' => 50.5], CellLatency::stats(range(100, 1, -1)));
        $this->assertSame(0.0, CellLatency::stats([])['p95']);
    }

    public function test_it_measures_the_database_a_request_reaches(): void
    {
        $r = app(CellLatency::class)->measure(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 10);
        $this->assertSame(0, $r['errors']);
        $this->assertGreaterThan(0, $r['request']['p50']);
        $this->assertGreaterThanOrEqual($r['connect']['p50'], $r['request']['p50']);
    }

    public function test_the_command_reports_compares_and_enforces_a_limit(): void
    {
        $this->assertSame(0, Artisan::call('cells:latency', ['--samples' => 5]));
        $out = Artisan::output();
        $this->assertStringContainsString('default', $out);
        $this->assertStringContainsString('request p95', $out);

        $this->assertSame(1, Artisan::call('cells:latency', ['--samples' => 5, '--max-p95' => '0']));
        $this->assertStringContainsString('over the 0 ms limit', Artisan::output());

        // an endpoint that refuses connections is reported, never hangs
        config(['database.connections.mysql.host' => '127.0.0.1', 'database.connections.mysql.port' => 1, 'database.default' => 'mysql']);
        $this->assertSame(1, Artisan::call('cells:latency', ['--samples' => 5]));
    }
}
