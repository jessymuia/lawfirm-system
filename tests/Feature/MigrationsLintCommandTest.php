<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DATA-13: the repository's own migrations pass the gate, and the gate can fail. */
class MigrationsLintCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_migrations_pass(): void
    {
        $this->artisan('migrations:lint')->assertExitCode(0);
    }

    public function test_a_new_destructive_migration_fails_the_gate(): void
    {
        $dir = sys_get_temp_dir().'/lint_'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/2030_01_01_000000_drop_thing.php', "<?php\nreturn new class {\n public function up() { Schema::table('t', fn (\$b) => \$b->dropColumn('x')); }\n public function down() {}\n};\n");

        $this->artisan('migrations:lint', ['--path' => [$dir], '--baseline' => $dir.'/none.txt'])->assertExitCode(1);

        // Grandfathering it in the baseline is the only other way through.
        file_put_contents($dir.'/base.txt', "2030_01_01_000000_drop_thing.php\n");
        $this->artisan('migrations:lint', ['--path' => [$dir], '--baseline' => $dir.'/base.txt'])->assertExitCode(0);

        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    public function test_baseline_only_lists_existing_files(): void
    {
        $names = array_filter(array_map('trim', file(database_path('migrations/lint-baseline.txt'))), fn ($l) => $l !== '' && $l[0] !== '#');

        foreach ($names as $name) {
            $this->assertFileExists(database_path('migrations/'.$name));
        }
    }
}
