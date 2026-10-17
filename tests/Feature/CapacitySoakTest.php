<?php

namespace Tests\Feature;

use App\Capacity\Soak;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** ENG-27: the capacity soak refuses where it must, and on MySQL/MariaDB measures and cleans up after itself. */
class CapacitySoakTest extends TestCase
{
    public function test_it_refuses_production_and_other_databases(): void
    {
        $this->app['env'] = 'production';
        $this->assertSame(1, Artisan::call('capacity:soak', ['--firms' => 1]));
        $this->assertStringContainsString('not production', Artisan::output());
        $this->app['env'] = 'testing';

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertSame(1, Artisan::call('capacity:soak', ['--firms' => 1]));
            $this->assertStringContainsString('MySQL/MariaDB', Artisan::output());
        }
    }

    public function test_a_small_soak_measures_each_step_and_drops_its_databases(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The soak measures MySQL/MariaDB servers (CI: backend-mysql).');
        }
        if (DB::select('SHOW TABLES') === []) {
            Artisan::call('migrate', ['--force' => true]);   // a firm schema to copy
        }
        $soak = new Soak(DB::connection(), 'capsoaktest_');
        $rows = $soak->run(4, 2, 20);

        $this->assertSame([2, 4], array_column($rows, 'firms'));
        $tables = count(DB::select('SHOW TABLES'));
        $this->assertSame([2 * $tables, 4 * $tables], array_column($rows, 'tables'));
        foreach ($rows as $r) {
            $this->assertGreaterThan(0, $r['provision_ms']);
            $this->assertGreaterThanOrEqual($r['request_p50_ms'], $r['request_p95_ms']);
        }
        $this->assertSame([], DB::select("SHOW DATABASES LIKE 'capsoaktest_%'"), 'scratch databases are dropped');
    }
}
