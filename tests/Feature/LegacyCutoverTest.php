<?php

namespace Tests\Feature;

use App\Services\Migration\LegacyFixture;
use App\Services\Migration\LegacyMapping;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CARRY-4 / CARRY-5: the legacy mapping report and the cutover rehearsal, on a practice database shaped like the legacy
 * production system (this app's pre-2026 schema with legacy-coded data). Needs MySQL/MariaDB and mysqldump (the
 * backend-mysql CI job); the mapping report's logic is also covered on SQLite.
 */
class LegacyCutoverTest extends TestCase
{
    private array $made = [];

    private string $files;

    private function mysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && trim((string) shell_exec('command -v mysqldump'));
    }

    /** A practice legacy database as the `source` connection. */
    private function legacy(bool $problems): string
    {
        $name = 'legacy_t'.substr(uniqid(), -6);
        $this->made[] = $name;
        DB::statement("CREATE DATABASE `{$name}`");
        $base = (array) config('database.connections.'.config('database.default'));
        config(['database.connections.source' => array_merge($base, ['database' => $name])]);
        DB::purge('source');
        $this->files = sys_get_temp_dir().'/legacy-files-'.uniqid();
        app(LegacyFixture::class)->build('source', 12, $problems, $this->files);

        return $name;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $db) {
            DB::statement("DROP DATABASE IF EXISTS `{$db}`");
        }
        foreach (DB::connection()->getDriverName() === 'sqlite' ? [] : DB::select("SHOW DATABASES LIKE 'cutover\\_%'") as $row) {
            DB::statement('DROP DATABASE IF EXISTS `'.array_values((array) $row)[0].'`');
        }
        isset($this->files) && File::deleteDirectory($this->files);
        parent::tearDown();
    }

    public function test_the_report_maps_known_values_and_flags_unknown_ones(): void
    {
        // the generic flags as the legacy app wrote them (Utils::createDefaultTableColumns), plus a value nobody documented;
        // a newer table's text `status` is its own state machine, not legacy coding. A database of its own (any driver).
        config(['database.connections.legacy_unit' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $db = DB::connection('legacy_unit');
        $db->getSchemaBuilder()->create('clients', function ($t) {
            $t->id();
            $t->string('name');
            $t->string('phone_number');
            $t->string('address');
            $t->tinyInteger('status')->default(1);
            $t->tinyInteger('archive')->default(0);
        });
        $db->getSchemaBuilder()->create('tenants', function ($t) {
            $t->id();
            $t->string('status');
        });
        $db->table('tenants')->insert(['status' => 'active']);
        $db->table('clients')->insert(['name' => 'A', 'phone_number' => '254700000001', 'address' => 'x', 'status' => 1, 'archive' => 0]);
        $db->table('clients')->insert(['name' => 'B', 'phone_number' => '254700000002', 'address' => 'x', 'status' => 7, 'archive' => 1]);
        $rows = collect(app(LegacyMapping::class)->inventory($db));

        $this->assertTrue($rows->firstWhere(fn ($r) => $r['column'] === 'clients.status' && $r['value'] === '1')['mapped']);
        $this->assertTrue($rows->firstWhere(fn ($r) => $r['column'] === 'clients.archive' && $r['value'] === '1')['mapped']);
        $seven = $rows->firstWhere(fn ($r) => $r['column'] === 'clients.status' && $r['value'] === '7');
        $this->assertFalse($seven['mapped']);
        $this->assertSame(1, $seven['count']);
        $this->assertNull($rows->firstWhere('column', 'tenants.status'), 'text status columns of newer tables are not legacy coding');

        $sheet = app(LegacyMapping::class)->signoffSheet($db, 'test');
        $this->assertStringContainsString('**UNMAPPED: what does this mean?**', $sheet);
        $this->assertStringContainsString('## Signed', $sheet);
        $this->assertSame(1, Artisan::call('migration:status-report', ['--connection' => 'legacy_unit']));
        $this->assertStringContainsString('1 value(s) are not in config/legacy_mapping.php', Artisan::output());
        $db->table('clients')->where('status', 7)->update(['status' => 1]);
        $this->assertSame(0, Artisan::call('migration:status-report', ['--connection' => 'legacy_unit']));
        $this->assertFalse(LegacyMapping::isLegacy($db), 'no invoices table: not a legacy database');
    }

    public function test_a_clean_legacy_database_upgrades_and_verifies(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('Needs MySQL/MariaDB and mysqldump.');
        }
        $this->legacy(false);
        $this->assertTrue(LegacyMapping::isLegacy(DB::connection('source')));
        $this->assertSame(0, Artisan::call('migration:status-report', ['--connection' => 'source', '--signoff' => $this->files.'/signoff.md']));
        $this->assertStringContainsString('Every value in this database is in the mapping', (string) file_get_contents($this->files.'/signoff.md'));

        $code = Artisan::call('migration:rehearse-cutover', ['--files' => $this->files]);
        $this->assertSame(0, $code, Artisan::output());
        $r = json_decode(Storage::disk('backups')->get(Storage::disk('backups')->files('cutover-rehearsals')[0]), true);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['blocking']);
        $this->assertSame(['draft' => 2, 'submitted' => 6, 'void' => 4], $r['verify']['invoice_states']['actual'], '1 -> submitted, 3 -> draft, 4/5 -> void');
        $this->assertSame($r['verify']['invoice_states']['expected'], $r['verify']['invoice_states']['actual']);
        $this->assertTrue($r['verify']['app_reads_data']);
        $this->assertSame(0, $r['verify']['pending_migrations']);
        $this->assertSame([], $r['verify']['invoice_totals_off']);
        $this->assertSame($r['before']['passwords'], $r['after']['passwords']);
        $this->assertSame($r['before']['rows']['invoices'], $r['after']['rows']['invoices']);
        $this->assertGreaterThan(0, $r['migrations_seconds']);
        // which migrations are slowest depends on the runner; the invoice refactor's effect is checked by invoice_states above
        $this->assertNotEmpty($r['slowest_migrations_seconds']);
        $this->assertLessThanOrEqual(8, count($r['slowest_migrations_seconds']));
        foreach (array_keys($r['slowest_migrations_seconds']) as $migration) {
            $this->assertFileExists(database_path("migrations/{$migration}.php"));
        }
        $this->assertSame([], DB::select("SHOW DATABASES LIKE '".$r['scratch_database']."'"), 'the copy is dropped');
        $this->assertTrue(LegacyMapping::isLegacy(DB::connection('source')), 'the source is never changed');
    }

    public function test_problems_are_reported_and_unmapped_values_stop_the_cutover(): void
    {
        if (! $this->mysql()) {
            $this->markTestSkipped('Needs MySQL/MariaDB and mysqldump.');
        }
        $this->legacy(true);
        $this->assertSame(1, Artisan::call('migration:status-report', ['--connection' => 'source']));

        $this->assertSame(1, Artisan::call('migration:rehearse-cutover', ['--files' => $this->files]));
        $stopped = json_decode(Storage::disk('backups')->get(Storage::disk('backups')->files('cutover-rehearsals')[0]), true);
        $this->assertFalse($stopped['ok']);
        $this->assertStringContainsString('invoices.status = 2', implode(' ', $stopped['blocking']));
        $this->assertStringContainsString('clients.status = 0', implode(' ', $stopped['blocking']));
        $this->assertArrayNotHasKey('upgrade', $stopped['timings'], 'stopped before upgrading');
        $warnings = implode(' ', $stopped['warnings']);
        $this->assertStringContainsString('cases.case_number: 1 row(s)', $warnings);
        $this->assertStringContainsString('invoice line(s) whose total is not', $warnings);
        $this->assertStringContainsString('document row(s) whose file is not', $warnings);

        // the real upgrade refuses the same database
        $default = config('database.default');
        config(['database.default' => 'source']);
        DB::setDefaultConnection('source');
        try {
            $this->assertSame(1, Artisan::call('app:upgrade', ['--dry-run' => true]));
            $this->assertStringContainsString('legacy value not in config/legacy_mapping.php: invoices.status = 2', Artisan::output());
        } finally {
            config(['database.default' => $default]);
            DB::setDefaultConnection($default);
        }

        Storage::fake('backups');
        $this->assertSame(0, Artisan::call('migration:rehearse-cutover', ['--files' => $this->files, '--accept-unmapped' => true]), Artisan::output());
        $passed = json_decode(Storage::disk('backups')->get(Storage::disk('backups')->files('cutover-rehearsals')[0]), true);
        $this->assertSame(1, $passed['verify']['renamed_case_numbers'], 'the duplicate was renamed with -DUP');
        $this->assertSame([3], $passed['verify']['documents_missing']);
    }
}
