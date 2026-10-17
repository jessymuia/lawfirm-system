<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CARRY-7: backup:run then backup:drill for real: a MySQL/MariaDB dump and a storage archive restored to a scratch
 * database and directory, checked and timed. Runs where the test database is MySQL/MariaDB with mysqldump installed
 * (the backend-mysql CI job); elsewhere only the refusals are checked.
 */
class BackupDrillTest extends TestCase
{
    private string $source = '';

    private string $files = '';

    private string $original = '';

    private function onMysql(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) && trim((string) shell_exec('command -v mysqldump'));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('backups');
        config(['backup.disk' => 'backups']);
        if (! $this->onMysql()) {
            return;
        }
        // a database of its own with committed rows (mysqldump cannot see a test transaction)
        $this->original = config('database.default');
        $conn = (array) config("database.connections.{$this->original}");
        $this->source = 'drill_src_'.substr(uniqid(), -6);
        DB::statement("CREATE DATABASE `{$this->source}`");
        config(["database.connections.{$this->original}.database" => $this->source]);
        DB::purge($this->original);
        Artisan::call('migrate', ['--force' => true]);
        $this->files = 'drilltest-'.substr(uniqid(), -6);
        config(['backup.file_paths' => [storage_path("app/{$this->files}")]]);
    }

    protected function tearDown(): void
    {
        if ($this->source !== '') {
            DB::statement("DROP DATABASE IF EXISTS `{$this->source}`");
            foreach (DB::select("SHOW DATABASES LIKE 'drill\\_2%'") as $row) {
                DB::statement('DROP DATABASE IF EXISTS `'.array_values((array) $row)[0].'`');
            }
            File::deleteDirectory(storage_path("app/{$this->files}"));
        }
        parent::tearDown();
    }

    private function seedOffice(bool $withFile = true): void
    {
        User::factory()->create(['email' => 'partner@drill.test']);
        $case = Cases::factory()->create(['client_id' => Client::factory()->create(['name' => 'Drill Client'])->id]);
        File::ensureDirectoryExists(storage_path("app/{$this->files}"));
        $path = "{$this->files}/brief.pdf";
        $withFile && File::put(storage_path("app/{$path}"), "%PDF-1.4\nthe brief");
        DB::table('documents')->insert(['case_id' => $case->id, 'title' => 'Brief', 'filename' => 'brief.pdf', 'filepath' => $path, 'full_path' => $path,
            'disk' => 'local', 'extension' => 'pdf', 'mimetype' => 'application/pdf', 'filesize' => 18, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_the_latest_backup_restores_to_a_working_database_and_files_and_is_timed(): void
    {
        if (! $this->onMysql()) {
            $this->markTestSkipped('Needs the test database on MySQL/MariaDB and mysqldump.');
        }
        $this->seedOffice();
        $this->assertSame(0, Artisan::call('backup:run'), Artisan::output());

        $code = Artisan::call('backup:drill');
        $out = Artisan::output();
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString('Restore drill passed', $out);

        $reports = Storage::disk('backups')->files('drills');
        $this->assertCount(1, $reports);
        $r = json_decode(Storage::disk('backups')->get($reports[0]), true);
        $this->assertTrue($r['ok']);
        $this->assertSame([], $r['problems']);
        $this->assertSame(count(DB::connection()->getSchemaBuilder()->getTableListing($this->source, false)), $r['checks']['tables'], 'every table of this database, and only those');
        $this->assertSame(1, $r['checks']['matters']);
        $this->assertSame(1, $r['checks']['documents_local']);
        $this->assertSame(0, $r['checks']['documents_missing']);
        $this->assertSame(0, $r['checks']['pending_migrations']);
        $this->assertTrue($r['checks']['app_reads_data']);
        foreach (['fetch', 'restore_database', 'restore_files', 'verify', 'cleanup', 'total'] as $phase) {
            $this->assertArrayHasKey($phase, $r['timings']);
        }
        $this->assertSame([], DB::select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$r['scratch']['database']]), 'the scratch database is removed');
        $this->assertDirectoryDoesNotExist($r['scratch']['directory']);
        $this->assertSame(1, User::where('email', 'partner@drill.test')->count(), 'the live database is untouched');
    }

    public function test_a_document_missing_from_the_archive_fails_the_drill_and_says_which(): void
    {
        if (! $this->onMysql()) {
            $this->markTestSkipped('Needs the test database on MySQL/MariaDB and mysqldump.');
        }
        $this->seedOffice(withFile: false);
        File::put(storage_path("app/{$this->files}/other.txt"), 'x');   // the archive exists, the brief is not in it
        $this->assertSame(0, Artisan::call('backup:run'));

        $this->assertSame(1, Artisan::call('backup:drill', ['--keep' => true]));
        $r = json_decode(Storage::disk('backups')->get(Storage::disk('backups')->files('drills')[0]), true);
        $this->assertFalse($r['ok']);
        $this->assertSame(1, $r['checks']['documents_missing']);
        $this->assertStringContainsString('missing from the archive', implode(' ', $r['problems']));
        $this->assertNotSame([], DB::select('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$r['scratch']['database']]), '--keep leaves it to look at');
        File::deleteDirectory($r['scratch']['directory']);
    }

    public function test_without_a_backup_or_on_another_database_it_refuses(): void
    {
        if ($this->onMysql()) {
            $this->assertSame(1, Artisan::call('backup:drill'));
            $this->assertStringContainsString('nothing to restore', Artisan::output());

            return;
        }
        $this->assertSame(1, Artisan::call('backup:drill'));
        $this->assertStringContainsString('restores MySQL/MariaDB dumps', Artisan::output());
    }
}
