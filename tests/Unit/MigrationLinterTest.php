<?php

namespace Tests\Unit;

use App\Support\MigrationLinter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DATA-13: the migration linter flags what the policy forbids and nothing else. */
class MigrationLinterTest extends TestCase
{
    private function migration(string $up, string $down = '', string $extra = ''): string
    {
        return "<?php\nreturn new class extends Migration {\n    public function up(): void\n    {\n{$up}\n    }\n{$extra}\n    public function down(): void\n    {\n{$down}\n    }\n};\n";
    }

    /** @return array<string, array{0:string,1:string}> */
    public static function forbidden(): array
    {
        return [
            'drop column' => ['drop-column', "Schema::table('t', fn (\$b) => \$b->dropColumn('x'));"],
            'drop columns' => ['drop-column', "Schema::table('t', fn (\$b) => \$b->dropColumns(['x','y']));"],
            'drop table' => ['drop-table', "Schema::dropIfExists('t');"],
            'rename column' => ['rename', "Schema::table('t', fn (\$b) => \$b->renameColumn('a','b'));"],
            'rename table' => ['rename', "Schema::rename('a','b');"],
            'change type' => ['change-type', "Schema::table('t', fn (\$b) => \$b->string('a', 10)->change());"],
            'drop unique' => ['drop-constraint', "Schema::table('t', fn (\$b) => \$b->dropUnique(['a']));"],
            'drop foreign' => ['drop-constraint', "Schema::table('t', fn (\$b) => \$b->dropForeign(['a']));"],
            'query update' => ['data-rewrite', "DB::table('t')->where('a', 1)->update(['a' => 2]);"],
            'multi-line update' => ['data-rewrite', "DB::table('t')\n    ->whereNull('a')\n    ->update(['a' => 2]);"],
            'delete' => ['data-rewrite', "DB::table('t')->where('a', 1)->delete();"],
            'chunk backfill' => ['data-rewrite', "DB::table('t')->orderBy('id')->chunkById(100, fn (\$rows) => null);"],
            'each backfill' => ['data-rewrite', "DB::table('t')->pluck('id')->each(fn (\$id) => null);"],
            'model update' => ['data-rewrite', "\\App\\Models\\User::query()->update(['a' => 1]);"],
            'raw update' => ['data-rewrite', "DB::statement('UPDATE t SET a = 1');"],
            'raw delete' => ['data-rewrite', 'DB::unprepared("DELETE FROM t WHERE a = 1");'],
            'raw alter drop' => ['raw-alter', "DB::statement('ALTER TABLE t DROP COLUMN a');"],
            'raw alter modify' => ['raw-alter', "DB::statement('ALTER TABLE t MODIFY a BIGINT');"],
        ];
    }

    #[DataProvider('forbidden')]
    public function test_forbidden_operation_is_flagged(string $rule, string $code): void
    {
        $rules = array_column((new MigrationLinter)->lint($this->migration($code)), 'rule');

        $this->assertContains($rule, $rules);
    }

    public function test_additive_migration_is_clean(): void
    {
        $up = <<<'PHP'
        Schema::create('widgets', function ($t) { $t->id(); $t->string('name'); $t->timestamps(); });
        Schema::table('clients', function ($t) { $t->string('nickname')->nullable(); $t->index('nickname'); });
        DB::unprepared('CREATE TRIGGER x BEFORE UPDATE ON t FOR EACH ROW BEGIN SELECT 1; END');
        DB::statement('ALTER TABLE t ADD COLUMN b INT NULL');
PHP;

        $this->assertSame([], (new MigrationLinter)->lint($this->migration($up)));
    }

    public function test_down_is_not_inspected(): void
    {
        $down = "Schema::dropIfExists('widgets'); Schema::table('t', fn (\$b) => \$b->dropColumn('x'));";

        $this->assertSame([], (new MigrationLinter)->lint($this->migration("Schema::create('widgets', fn (\$t) => \$t->id());", $down)));
    }

    public function test_helpers_called_from_up_are_inspected(): void
    {
        // The backlog's own example (2026_08_19_090300): the rewrite hides in a helper.
        $helper = "    private function dedupe(): void\n    {\n        DB::table('t')->where('id', 1)->update(['a' => 'x']);\n    }\n";

        $this->assertContains('data-rewrite', array_column((new MigrationLinter)->lint($this->migration('$this->dedupe();', '', $helper)), 'rule'));
    }

    public function test_allow_comment_with_a_reason_accepts_the_finding(): void
    {
        $code = "// migration-lint: allow drop-column -- column unread since release 4.2\nSchema::table('t', fn (\$b) => \$b->dropColumn('x'));";

        $this->assertSame([], (new MigrationLinter)->lint($this->migration($code)));
    }

    public function test_allow_comment_without_a_reason_is_itself_a_finding(): void
    {
        $code = "// migration-lint: allow drop-column\nSchema::table('t', fn (\$b) => \$b->dropColumn('x'));";
        $rules = array_column((new MigrationLinter)->lint($this->migration($code)), 'rule');

        $this->assertContains('bad-allow', $rules);
        $this->assertContains('drop-column', $rules, 'a reasonless allow must not suppress the finding');
    }

    public function test_allow_for_an_unknown_rule_is_a_finding(): void
    {
        $code = "// migration-lint: allow drop-everything -- because reasons here\nSchema::dropIfExists('t');";
        $rules = array_column((new MigrationLinter)->lint($this->migration($code)), 'rule');

        $this->assertContains('bad-allow', $rules);
        $this->assertContains('drop-table', $rules);
    }

    public function test_allow_is_per_rule(): void
    {
        $code = "// migration-lint: allow drop-column -- column unread since release 4.2\nSchema::table('t', fn (\$b) => \$b->dropColumn('x'));\nSchema::dropIfExists('u');";

        $this->assertSame(['drop-table'], array_column((new MigrationLinter)->lint($this->migration($code)), 'rule'));
    }

    public function test_reports_the_line_number(): void
    {
        $findings = (new MigrationLinter)->lint($this->migration("// comment\nSchema::dropIfExists('t');"));

        $this->assertSame(6, $findings[0]['line']);
    }
}
