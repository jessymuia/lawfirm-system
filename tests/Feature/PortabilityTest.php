<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Portability\Bundle;
use App\Portability\Exporter;
use App\Portability\Format;
use App\Portability\Importer;
use App\Portability\PortabilityException;
use App\Portability\RowHash;
use App\Portability\TableCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PLAT-9: eject / import against a real seeded firm. The source is the test DB; the target is a second, freshly
 * migrated SQLite database, so the data really travels through a bundle on disk.
 */
class PortabilityTest extends TestCase
{
    use RefreshDatabase, \Tests\CreatesTestUsers;

    private string $work;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = config('database.default');
        $this->work = sys_get_temp_dir().'/plat9-'.uniqid();
        mkdir($this->work);
        config(['filesystems.disks.srcdocs' => ['driver' => 'local', 'root' => "{$this->work}/src-docs"],
            'filesystems.disks.dstdocs' => ['driver' => 'local', 'root' => "{$this->work}/dst-docs"]]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->source);
        is_dir($this->work) && Bundle::rmrf($this->work);
        parent::tearDown();
    }

    // ---- helpers -------------------------------------------------------------------------------------------------

    /** Insert one row, filling every required column with a plausible value for its type. */
    private function row(string $table, array $over = []): int
    {
        $row = [];
        $fk = [];
        foreach (Schema::getForeignKeys($table) as $f) {
            foreach ($f['columns'] as $i => $col) {
                $fk[$col] = [$f['foreign_table'], $f['foreign_columns'][$i]];
            }
        }
        foreach (Schema::getColumns($table) as $c) {
            $n = $c['name'];
            if (array_key_exists($n, $over)) {
                $row[$n] = $over[$n];

                continue;
            }
            if ($c['auto_increment'] || $c['nullable'] || $c['default'] !== null) {
                continue;
            }
            if (isset($fk[$n])) {   // a real parent row, so engines that enforce foreign keys accept it
                $row[$n] = DB::table($fk[$n][0])->value($fk[$n][1]) ?? throw new \LogicException("seed a parent in {$fk[$n][0]} first ({$table}.{$n})");

                continue;
            }
            $t = strtolower($c['type_name'] ?? $c['type']);
            $row[$n] = match (true) {
                str_contains($t, 'int'), str_contains($t, 'bool') => 1,
                str_contains($t, 'decimal'), str_contains($t, 'float'), str_contains($t, 'double'), str_contains($t, 'numeric') => '10.50',
                str_contains($t, 'datetime'), str_contains($t, 'timestamp') => now()->toDateTimeString(),
                str_contains($t, 'date') => now()->toDateString(),
                str_contains($t, 'json') => '{}',
                default => 'x'.$n,
            };
        }

        return (int) DB::table($table)->insertGetId($row + $over);
    }

    /** A believable firm: people, a matter chain, money, a screen, a grant, audit rows, three real document files. */
    private function seedFirm(): array
    {
        Artisan::call('law:setup-permissions');
        $admin = $this->adminUser();
        $advocate = User::factory()->create(['name' => 'Wanjiru Advocate']);
        $client = Client::factory()->create();
        $case = Cases::factory()->create(['client_id' => $client->id]);
        $hearing = \App\Models\Hearing::factory()->create(['case_id' => $case->id]);

        $invoice = $this->row('invoices', ['case_id' => $case->id, 'client_id' => $client->id]);
        $this->row('invoice_items', ['invoice_id' => $invoice]);
        $this->row('payments', ['invoice_id' => $invoice, 'received_by' => $admin->id, 'method' => 'cash']);
        $this->row('time_entries', ['case_id' => $case->id, 'user_id' => $advocate->id]);
        $this->row('trust_transactions', ['case_id' => $case->id, 'client_id' => $client->id, 'type' => 'deposit']);
        $this->row('matter_access_grants', ['case_id' => $case->id, 'user_id' => $advocate->id]);
        $this->row('ethical_screens', ['user_id' => $advocate->id, 'case_id' => $case->id]);

        $files = [];
        foreach (['a.pdf' => "%PDF-1.4 alpha \x00\xff binary", 'b.txt' => "plain text é ü", 'c.bin' => random_bytes(3000)] as $name => $bytes) {
            $path = "tenants/default/cases/docs/{$name}";
            Storage::disk('srcdocs')->put($path, $bytes);
            $id = $this->row('documents', ['case_id' => $case->id, 'filename' => $name, 'filepath' => $path, 'full_path' => $path, 'disk' => 'srcdocs',
                'checksum' => hash('sha256', $bytes), 'filesize' => strlen($bytes)]);
            $files[$id] = $bytes;
        }
        DB::table('audits')->insert(['user_type' => User::class, 'user_id' => $admin->id, 'event' => 'created', 'auditable_type' => Cases::class,
            'auditable_id' => $case->id, 'old_values' => '[]', 'new_values' => '{"x":1}', 'created_at' => now(), 'updated_at' => now()]);

        // a platform support principal: must never travel
        $principal = User::factory()->create(['email' => 'support-1-abc@support.invalid']);
        DB::table('users')->where('id', $principal->id)->update(['platform_staff_id' => 77]);
        $role = \Spatie\Permission\Models\Role::findOrCreate('support-read', 'web');
        $principal->assignRole($role);

        return compact('admin', 'advocate', 'client', 'case', 'invoice', 'files', 'principal');
    }

    private function exportTo(string $name = 'bundle', bool $files = true): array
    {
        $manifest = (new Exporter)->export("{$this->work}/{$name}", $files);

        return [Bundle::open("{$this->work}/{$name}"), $manifest];
    }

    /** Switch to a brand-new migrated database and return its connection name. */
    private function freshTarget(bool $seedInstall = false): string
    {
        if (getenv('PORTABILITY_TARGET_MYSQL')) {   // same-engine run: MariaDB/MySQL source AND target
            config(['database.connections.target' => array_merge(config('database.connections.mysql'), ['database' => 'lawtarget'])]);
            DB::purge('target');
            DB::setDefaultConnection('target');
            Artisan::call('db:wipe', ['--database' => 'target', '--force' => true]);
        } else {
            $file = "{$this->work}/target-".uniqid().'.sqlite';
            touch($file);
            config(['database.connections.target' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true]]);
            DB::purge('target');
            DB::setDefaultConnection('target');
        }
        // a different machine: the same disk name, an empty storage root
        config(['filesystems.disks.srcdocs' => ['driver' => 'local', 'root' => "{$this->work}/machine2-".uniqid()]]);
        Storage::forgetDisk('srcdocs');
        Artisan::call('migrate', ['--database' => 'target', '--force' => true]);
        if ($seedInstall) {
            Artisan::call('law:setup-permissions');
            \App\Support\RoleCatalog::sync();
            User::factory()->create(['email' => 'bootstrap-admin@example.test']);
        }

        return 'target';
    }

    private function snapshot(): array
    {
        $out = [];
        foreach (TableCatalog::included() as $t) {
            $h = new RowHash;
            foreach (DB::table($t)->get() as $r) {
                $h->add((array) $r);
            }
            $out[$t] = [$h->count(), $h->result()];
        }

        return $out;
    }

    // ---- the round trip ------------------------------------------------------------------------------------------

    public function test_a_seeded_firm_survives_eject_and_import_exactly(): void
    {
        $firm = $this->seedFirm();
        [$bundle, $manifest] = $this->exportTo();
        $this->assertSame([], $bundle->verify());

        // principals are not in the bundle
        $this->assertSame(DB::table('users')->count() - 1, $manifest['tables']['users']['rows']);
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $firm['principal']->id)->count() ? 0 : 0);
        $exportedUsers = iterator_to_array($bundle->rows('users'));
        $this->assertNotContains($firm['principal']->id, array_column($exportedUsers, 'id'));
        $roles = array_column(iterator_to_array($bundle->rows('roles')), 'name');
        $this->assertNotContains('support-read', $roles);

        $before = $this->snapshot();
        $beforeUsers = collect($exportedUsers)->keyBy('id');

        $this->freshTarget(seedInstall: true);
        $result = (new Importer)->import($bundle, ['replace_bootstrap' => true]);
        $this->assertSame(3, $result['files']);

        // every included table: same row count and same content hash, ids preserved
        $after = $this->snapshot();
        foreach ($manifest['tables'] as $t => $info) {
            $this->assertSame($info['rows'], $after[$t][0], "$t row count");
        }
        $this->assertSame($manifest['tables']['cases']['row_checksum'], $this->tableChecksum('cases', $manifest));
        $this->assertSame($manifest['tables']['audits']['row_checksum'], $this->tableChecksum('audits', $manifest));
        $this->assertSame($manifest['tables']['users']['row_checksum'], $this->tableChecksum('users', $manifest));

        // ids and relationships intact
        $this->assertSame($firm['case']->id, (int) DB::table('cases')->where('case_number', $firm['case']->case_number)->value('id'));
        $this->assertSame($firm['client']->id, (int) DB::table('cases')->where('id', $firm['case']->id)->value('client_id'));
        $this->assertSame($firm['invoice'], (int) DB::table('payments')->value('invoice_id'));
        $this->assertSame(0, DB::table('users')->where('platform_staff_id', '!=', null)->count());
        $this->assertNull(DB::table('users')->where('email', 'bootstrap-admin@example.test')->first(), 'bootstrap rows were replaced');
        // passwords travel as hashes, so people can sign in
        $this->assertSame($beforeUsers[$firm['admin']->id]['password'], DB::table('users')->where('id', $firm['admin']->id)->value('password'));

        // document bytes identical, and the rows still point at them
        foreach ($firm['files'] as $id => $bytes) {
            $row = DB::table('documents')->find($id);
            $this->assertSame($bytes, Storage::disk($row->disk)->get($row->full_path));
        }
    }

    /** The target table's content hash over exactly the columns the bundle carries. */
    private function tableChecksum(string $table, array $manifest): string
    {
        $h = new RowHash;
        foreach (DB::table($table)->select($manifest['tables'][$table]['columns'])->orderBy('id')->get() as $r) {
            $h->add((array) $r);
        }

        return $h->result();
    }

    public function test_documents_can_be_stored_on_another_disk(): void
    {
        $firm = $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        (new Importer)->import($bundle, ['replace_bootstrap' => true, 'files_disk' => 'dstdocs']);

        foreach ($firm['files'] as $id => $bytes) {
            $row = DB::table('documents')->find($id);
            $this->assertSame('dstdocs', $row->disk);
            $this->assertSame($bytes, Storage::disk('dstdocs')->get($row->full_path));
        }
    }

    public function test_the_importer_reports_a_dry_run_and_changes_nothing(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        $r = (new Importer)->import($bundle, ['replace_bootstrap' => true, 'dry_run' => true]);
        $this->assertTrue($r['dry_run']);
        $this->assertSame(0, DB::table('cases')->count());
        $this->assertCount(0, Storage::disk('dstdocs')->allFiles());
    }

    // ---- refusals ------------------------------------------------------------------------------------------------

    public function test_it_never_merges_into_a_target_that_has_data(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        $this->row('sequences');

        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('not empty: sequences');
        (new Importer)->import($bundle, ['replace_bootstrap' => true]);
    }

    public function test_a_first_install_target_needs_replace_bootstrap(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget(seedInstall: true);

        try {
            (new Importer)->import($bundle);
            $this->fail('should refuse');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('--replace-bootstrap', $e->getMessage());
        }
        $this->assertSame(1, DB::table('users')->count(), 'untouched');
    }

    public function test_a_target_older_than_the_bundle_is_refused(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        DB::table('migrations')->orderByDesc('id')->limit(1)->delete();

        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('older than the bundle');
        (new Importer)->import($bundle, ['replace_bootstrap' => true]);
    }

    public function test_a_newer_target_with_extra_migrations_still_imports(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        DB::table('migrations')->insert(['migration' => '2099_01_01_000000_future', 'batch' => 99]);
        Schema::table('clients', fn ($t) => $t->string('future_col')->nullable());

        (new Importer)->import($bundle, ['replace_bootstrap' => true]);
        $this->assertGreaterThan(0, DB::table('clients')->count());
    }

    public function test_a_missing_disk_is_refused_before_anything_is_written(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        config(['filesystems.disks.srcdocs' => null]);
        Storage::forgetDisk('srcdocs');

        try {
            (new Importer)->import($bundle, ['replace_bootstrap' => true]);
            $this->fail('should refuse');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('files-disk', $e->getMessage());
        }
        $this->assertSame(0, DB::table('clients')->count());
    }

    // ---- tampering and integrity ---------------------------------------------------------------------------------

    public function test_every_kind_of_tampering_is_detected(): void
    {
        $this->seedFirm();
        $this->exportTo('t0');

        // a data row edited
        $this->copyBundle('t0', 't1');
        $f = "{$this->work}/t1/data/clients.ndjson";
        file_put_contents($f, str_replace('"name":"', '"name":"EVIL ', file_get_contents($f)));
        $this->assertNotEmpty(Bundle::open("{$this->work}/t1")->verify());

        // a document swapped
        $this->copyBundle('t0', 't2');
        $doc = glob("{$this->work}/t2/files/[0-9]*")[0];
        file_put_contents($doc, 'swapped');
        $this->assertStringContainsString('checksum mismatch', implode('|', Bundle::open("{$this->work}/t2")->verify()));

        // the manifest edited (e.g. to hide the above)
        $this->copyBundle('t0', 't3');
        $mf = "{$this->work}/t3/manifest.json";
        file_put_contents($mf, str_replace('"rows": 1', '"rows": 2', file_get_contents($mf)));
        $this->assertStringContainsString('seal', implode('|', Bundle::open("{$this->work}/t3")->verify()));

        // a file removed
        $this->copyBundle('t0', 't4');
        unlink("{$this->work}/t4/data/cases.ndjson");
        $this->assertStringContainsString('data file missing', implode('|', Bundle::open("{$this->work}/t4")->verify()));

        // rows added AND the table file checksum fixed up in an unsealed manifest still fail the seal; and the intact one passes
        $this->assertSame([], Bundle::open("{$this->work}/t0")->verify());
    }

    private function copyBundle(string $from, string $to): void
    {
        mkdir("{$this->work}/{$to}/data", 0700, true);
        mkdir("{$this->work}/{$to}/files", 0700, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$this->work}/{$from}", \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                copy($f->getPathname(), "{$this->work}/{$to}/".substr($f->getPathname(), strlen("{$this->work}/{$from}/")));
            }
        }
    }

    public function test_a_tampered_bundle_is_refused_by_the_importer(): void
    {
        $this->seedFirm();
        $this->exportTo('t0');
        $this->copyBundle('t0', 't1');
        file_put_contents("{$this->work}/t1/data/clients.ndjson", "\n", FILE_APPEND);   // even a stray newline changes the file hash
        file_put_contents("{$this->work}/t1/data/cases.ndjson", 'garbage');
        $this->freshTarget();

        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('failed verification');
        (new Importer)->import(Bundle::open("{$this->work}/t1"), ['replace_bootstrap' => true]);
    }

    public function test_a_failure_mid_import_rolls_everything_back_and_removes_copied_files(): void
    {
        $this->seedFirm();
        [$bundle, $manifest] = $this->exportTo('t0');
        // Re-seal a bundle whose rows cannot load: a duplicate primary key in cases (bypasses the transport checks on purpose).
        $this->copyBundle('t0', 't1');
        $f = "{$this->work}/t1/data/cases.ndjson";
        $line = trim(explode("\n", file_get_contents($f))[0]);
        file_put_contents($f, "{$line}\n{$line}\n");
        $this->reseal('t1');

        $this->freshTarget();
        try {
            (new Importer)->import(Bundle::open("{$this->work}/t1"), ['replace_bootstrap' => true, 'files_disk' => 'dstdocs']);
            $this->fail('should fail');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('rolled back', $e->getMessage());
        }
        foreach (TableCatalog::included() as $t) {
            if (! in_array($t, TableCatalog::bootstrap(), true)) {
                $this->assertSame(0, DB::table($t)->count(), "$t must be empty after a failed import");
            }
        }
        $this->assertCount(0, Storage::disk('dstdocs')->allFiles(), 'copied documents are removed again');
    }

    /** Recompute checksums and the seal after a deliberate edit, as a careful attacker (or a bug) would. */
    private function reseal(string $name): void
    {
        $dir = "{$this->work}/{$name}";
        $m = json_decode(file_get_contents("{$dir}/manifest.json"), true);
        foreach ($m['tables'] as $t => &$info) {
            $h = new RowHash;
            $n = 0;
            foreach (file("{$dir}/{$info['file']}", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $h->add(json_decode($line, true));
            }
            $info['sha256'] = hash_file('sha256', "{$dir}/{$info['file']}");
            $info['rows'] = $h->count();
            $info['row_checksum'] = $h->result();
        }
        $json = json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents("{$dir}/manifest.json", $json);
        file_put_contents("{$dir}/manifest.sha256", hash('sha256', $json)."  manifest.json\n");
    }


    public function test_silent_corruption_during_load_is_caught_and_nothing_is_kept(): void
    {
        if (getenv('PORTABILITY_TARGET_MYSQL')) {
            $this->markTestSkipped('uses an SQLite trigger');
        }
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        // a hostile or buggy target quietly rewrites rows as they arrive (target is always SQLite here)
        DB::statement("CREATE TRIGGER mangle AFTER INSERT ON clients BEGIN UPDATE clients SET name = 'mangled' WHERE id = NEW.id; END");

        try {
            (new Importer)->import($bundle, ['replace_bootstrap' => true, 'files_disk' => 'dstdocs']);
            $this->fail('verification must catch it');
        } catch (PortabilityException $e) {
            $this->assertStringContainsString('Verification failed for clients', $e->getMessage());
        }
        $this->assertSame(0, DB::table('clients')->count());
        $this->assertSame(0, DB::table('cases')->count());
        $this->assertCount(0, Storage::disk('dstdocs')->allFiles());
    }

    public function test_a_damaged_document_copy_is_caught(): void
    {
        $this->seedFirm();
        [$bundle] = $this->exportTo();
        $this->freshTarget();
        // a disk whose writes lose a byte
        Storage::extend('lossy', fn ($app, $config) => new \Illuminate\Filesystem\FilesystemAdapter(
            new \League\Flysystem\Filesystem(new class("{$this->work}/lossy") extends \League\Flysystem\Local\LocalFilesystemAdapter {
                public function writeStream(string $path, $contents, \League\Flysystem\Config $config): void
                {
                    $data = stream_get_contents($contents);
                    $this->write($path, substr($data, 0, -1), $config);
                }
            }),
            new \League\Flysystem\Local\LocalFilesystemAdapter("{$this->work}/lossy"),
            $config
        ));
        config(['filesystems.disks.lossy' => ['driver' => 'lossy', 'root' => "{$this->work}/lossy"]]);

        $this->expectException(PortabilityException::class);
        $this->expectExceptionMessage('damaged while being copied');
        (new Importer)->import($bundle, ['replace_bootstrap' => true, 'files_disk' => 'lossy']);
    }

    // ---- format versioning ---------------------------------------------------------------------------------------

    public function test_format_version_rules(): void
    {
        $this->assertNull(Format::incompatibility(Format::NAME, Format::VERSION));
        $this->assertNull(Format::incompatibility(Format::NAME, '1.0.0'));
        $this->assertStringContainsString('major', Format::incompatibility(Format::NAME, '2.0.0'));
        $this->assertStringContainsString('major', Format::incompatibility(Format::NAME, '0.9.0'));
        $this->assertStringContainsString('newer', Format::incompatibility(Format::NAME, '1.99.0'));
        $this->assertStringContainsString('Not a lawfirm', Format::incompatibility('other', '1.0.0'));
        $this->assertStringContainsString('valid format version', Format::incompatibility(Format::NAME, 'abc'));
    }

    public function test_every_table_is_classified_and_exclusions_have_reasons(): void
    {
        $this->assertSame([], TableCatalog::unclassified(), 'a new table must be put in config/portability.php include or exclude');
        foreach ((array) config('portability.exclude') as $t => $why) {
            $this->assertNotEmpty($why, "$t needs a reason");
        }
        $this->assertSame([], array_values(array_intersect((array) config('portability.include'), array_keys((array) config('portability.exclude')))));
        $this->assertSame([], array_values(array_diff((array) config('portability.bootstrap'), (array) config('portability.include'))));
    }

    public function test_an_unclassified_table_blocks_the_export(): void
    {
        Schema::create('brand_new_feature', fn ($t) => $t->id());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('brand_new_feature');
        (new Exporter)->export("{$this->work}/x");
    }

    public function test_the_bundle_never_contains_secrets_that_must_stay_behind(): void
    {
        $this->seedFirm();
        DB::table('personal_access_tokens')->insert(['tokenable_type' => User::class, 'tokenable_id' => 1, 'name' => 't', 'token' => 'SECRETTOKEN', 'created_at' => now(), 'updated_at' => now()]);
        $this->exportTo();
        $all = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator("{$this->work}/bundle/data", \FilesystemIterator::SKIP_DOTS)) as $f) {
            $all .= file_get_contents($f->getPathname());
        }
        $this->assertStringNotContainsString('SECRETTOKEN', $all);
        $this->assertStringNotContainsString('support.invalid', $all);
    }

    #[\PHPUnit\Framework\Attributes\Group('single-mode')]   // install to install; a firm's round trip is PortabilityTenantTest
    public function test_zip_round_trip_through_the_commands(): void
    {
        $firm = $this->seedFirm();
        $zip = "{$this->work}/firm.zip";
        $this->artisan('portability:export', ['--zip' => true, '--output' => $zip])->assertSuccessful();
        $this->assertFileExists($zip);
        $this->artisan('portability:verify', ['input' => $zip])->expectsOutputToContain('Bundle OK')->assertSuccessful();

        $this->freshTarget();
        $this->artisan('portability:import', ['input' => $zip, '--dry-run' => true, '--replace-bootstrap' => true])->expectsOutputToContain('Dry run OK')->assertSuccessful();
        $this->artisan('portability:import', ['input' => $zip, '--replace-bootstrap' => true])->expectsOutputToContain('Imported and verified')->assertSuccessful();
        $this->assertSame($firm['case']->id, (int) DB::table('cases')->value('id'));

        // a second import refuses (not empty)
        $this->artisan('portability:import', ['input' => $zip, '--replace-bootstrap' => true])->expectsOutputToContain('not empty')->assertFailed();
    }

    public function test_a_zip_with_a_path_escape_is_refused(): void
    {
        $zip = new \ZipArchive;
        $zip->open("{$this->work}/evil.zip", \ZipArchive::CREATE);
        $zip->addFromString('../escape.txt', 'x');
        $zip->close();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsafe path');
        Bundle::open("{$this->work}/evil.zip");
    }

    public function test_a_manifest_cannot_point_outside_the_bundle(): void
    {
        $this->seedFirm();
        $this->exportTo('t0');
        $this->copyBundle('t0', 't1');
        $m = json_decode(file_get_contents("{$this->work}/t1/manifest.json"), true);
        $m['tables']['clients']['file'] = '../../../etc/passwd';
        $json = json_encode($m);
        file_put_contents("{$this->work}/t1/manifest.json", $json);
        file_put_contents("{$this->work}/t1/manifest.sha256", hash('sha256', $json)."  manifest.json\n");

        $this->assertStringContainsString('data file missing', implode('|', Bundle::open("{$this->work}/t1")->verify()));
    }

    public function test_missing_document_files_are_reported_not_fatal(): void
    {
        $firm = $this->seedFirm();
        $someId = array_key_first($firm['files']);
        Storage::disk('srcdocs')->delete(DB::table('documents')->find($someId)->full_path);

        [$bundle, $manifest] = $this->exportTo();
        $this->assertSame(1, $manifest['files']['missing']);
        $this->assertSame(2, $manifest['files']['count']);
        $this->assertSame([], $bundle->verify());

        $this->freshTarget();
        $r = (new Importer)->import($bundle, ['replace_bootstrap' => true, 'files_disk' => 'dstdocs']);
        $this->assertStringContainsString('missing at export', implode(' ', $r['notes']));
    }

    // ---- the format is an API: a frozen v1 bundle must keep importing --------------------------------------------

    private const GOLDEN = __DIR__.'/../fixtures/export-v1.0.0';

    /** Run once with REGENERATE_EXPORT_FIXTURE=1 when (and only when) the format version is deliberately bumped. */
    public function test_regenerate_the_golden_fixture(): void
    {
        if (! getenv('REGENERATE_EXPORT_FIXTURE')) {
            $this->markTestSkipped('Only when deliberately cutting a new format version.');
        }
        $this->seedFirm();
        is_dir(self::GOLDEN) && Bundle::rmrf(self::GOLDEN);
        (new Exporter)->export(self::GOLDEN, true);
        $this->assertTrue(true);
    }

    public function test_the_frozen_v1_bundle_still_verifies_and_imports(): void
    {
        $this->assertDirectoryExists(self::GOLDEN, 'tests/fixtures/export-v1.0.0 must be committed');
        $bundle = Bundle::open(self::GOLDEN);
        $this->assertSame('1.0.0', $bundle->manifest['version'], 'the fixture is the frozen 1.0.0 bundle: never edit it, add a new one');
        $this->assertSame([], $bundle->verify(), 'this release no longer reads format 1.0.0: that is a breaking change (bump Format major, add a new fixture, document it)');

        $this->freshTarget();
        (new Importer)->import($bundle, ['replace_bootstrap' => true]);
        foreach ($bundle->manifest['tables'] as $t => $info) {
            $this->assertSame($info['rows'], DB::table($t)->count(), $t);
        }
        $this->assertGreaterThan(0, DB::table('cases')->count());
        foreach ($bundle->fileEntries() as $e) {
            $row = DB::table('documents')->find($e['document_id']);
            $this->assertSame($e['sha256'], hash('sha256', Storage::disk($row->disk)->get($row->full_path)));
        }
    }

    public function test_a_new_export_has_the_same_shape_as_the_frozen_one(): void
    {
        $golden = json_decode(file_get_contents(self::GOLDEN.'/manifest.json'), true);
        $this->seedFirm();
        [, $now] = $this->exportTo();

        $this->assertSame(array_keys($golden), array_keys($now), 'manifest keys changed: that is a format change');
        $this->assertSame(array_keys($golden['files']), array_keys($now['files']));
        $this->assertSame(array_keys(reset($golden['tables'])), array_keys(reset($now['tables'])));
        $this->assertSame([], array_diff(array_keys($golden['tables']), array_keys($now['tables'])), 'a table was dropped from the format');
        foreach ($golden['tables'] as $t => $info) {
            $this->assertSame([], array_diff($info['columns'], $now['tables'][$t]['columns']), "$t lost a column the v1 format carries");
        }
        $this->assertSame(Format::VERSION, $now['version']);
    }
}
