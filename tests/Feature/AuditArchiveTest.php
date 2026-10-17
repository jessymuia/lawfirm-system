<?php

namespace Tests\Feature;

use App\Audit\AuditArchiver;
use App\Audit\AuditPartitions;
use App\Entitlements\Entitlements;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** DATA-11: the audit trail leaves the live table by month, verified, and archives respect retention and holds. */
class AuditArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['audit_retention.disk' => 'local', 'audit_retention.chunk' => 2]);
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        DB::table('audits')->delete();
    }

    private function online(?int $months): void
    {
        config(['entitlements.plans.self_hosted.limits.audit_online_months' => $months]);
        app(Entitlements::class)->forget();
    }

    private function audit(string $at, string $event = 'updated'): int
    {
        return DB::table('audits')->insertGetId([
            'user_type' => 'App\\Models\\User', 'user_id' => 1, 'event' => $event, 'auditable_type' => 'App\\Models\\Client', 'auditable_id' => 7,
            'old_values' => '{"name":"Old"}', 'new_values' => '{"name":"New"}', 'url' => 'https://x.test', 'ip_address' => '10.0.0.1',
            'user_agent' => 'test', 'tags' => null, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    /** the active firm: `default` on a single-firm install, the test firm in multi mode (TEN-17) */
    private function firm(): string
    {
        return app(\App\Tenancy\TenantContext::class)->key();
    }

    private function prefix(string $path): string
    {
        return app(\App\Tenancy\TenantContext::class)->storagePrefix($path);
    }

    private function archiver(): AuditArchiver
    {
        return app(AuditArchiver::class);
    }

    public function test_months_older_than_the_plan_window_are_due_and_the_current_month_never_is(): void
    {
        $this->audit('2026-06-30 23:59:59');
        $this->audit('2026-07-01 00:00:00');
        $this->audit('2026-10-01 08:00:00');

        $this->online(null);
        $this->assertSame([], $this->archiver()->dueMonths(), 'unlimited plans keep everything online');

        $this->online(3);   // October, September, August stay online
        $this->assertSame(['2026-06', '2026-07'], $this->archiver()->dueMonths());

        $this->online(0);
        $this->assertNotContains('2026-10', $this->archiver()->dueMonths());
    }

    public function test_a_month_is_written_verified_recorded_and_only_then_removed(): void
    {
        $ids = [$this->audit('2026-06-01 00:00:00'), $this->audit('2026-06-15 10:00:00'), $this->audit('2026-06-30 23:59:59', 'deleted')];
        $kept = $this->audit('2026-07-01 00:00:00');

        $r = $this->archiver()->archive('2026-06');

        $this->assertSame(['month' => '2026-06', 'rows' => 3, 'removed' => 3, 'path' => $this->prefix('audit-archive/2026-06.jsonl.gz'), 'how' => 'rows'], $r);
        $raw = Storage::disk('local')->get($r['path']);
        $lines = array_map(fn ($l) => json_decode($l, true), array_values(array_filter(explode("\n", gzdecode($raw)))));
        $this->assertSame($ids, array_column($lines, 'id'));
        $this->assertSame('deleted', $lines[2]['event']);
        $this->assertSame('{"name":"Old"}', $lines[0]['old_values'], 'every column travels as stored');

        $row = DB::table('audit_archives')->where('month', '2026-06')->first();
        $this->assertSame(hash('sha256', $raw), $row->sha256);
        $this->assertSame([3, $ids[0], $ids[2]], [(int) $row->rows, (int) $row->first_id, (int) $row->last_id]);
        $this->assertSame([$kept], DB::table('audits')->pluck('id')->all());
    }

    public function test_a_run_that_stopped_after_recording_is_finished_without_rewriting_the_file(): void
    {
        $a = $this->audit('2026-06-10 00:00:00');
        $this->archiver()->archive('2026-06');
        $sha = DB::table('audit_archives')->value('sha256');
        // as if the rows had not been deleted yet, plus one row that arrived later with an old timestamp
        DB::table('audits')->insert(['id' => $a, 'event' => 'updated', 'auditable_type' => 'X', 'auditable_id' => 1, 'created_at' => '2026-06-10 00:00:00', 'updated_at' => '2026-06-10 00:00:00']);
        $late = $this->audit('2026-06-20 00:00:00');

        $r = $this->archiver()->archive('2026-06');

        $this->assertSame(['resumed', 1], [$r['how'], $r['removed']]);
        $this->assertSame([$late], DB::table('audits')->pluck('id')->all(), 'a row not in the file is never removed');
        $this->assertSame($sha, DB::table('audit_archives')->value('sha256'));
    }

    public function test_a_month_can_be_restored_and_a_tampered_archive_is_refused(): void
    {
        $ids = [$this->audit('2026-05-02 00:00:00'), $this->audit('2026-05-03 00:00:00')];
        $r = $this->archiver()->archive('2026-05');
        DB::table('audits')->insert(['id' => $ids[0], 'event' => 'present', 'auditable_type' => 'X', 'auditable_id' => 1, 'created_at' => '2026-05-02 00:00:00']);

        $this->assertSame(1, $this->archiver()->restore('2026-05'), 'rows still present are kept as they are');
        $this->assertSame($ids, DB::table('audits')->orderBy('id')->pluck('id')->all());
        $this->assertSame('present', DB::table('audits')->where('id', $ids[0])->value('event'));

        Storage::disk('local')->put($r['path'], gzencode('{"id":999}'));
        $this->expectExceptionMessage('does not match its recorded SHA-256');
        $this->archiver()->restore('2026-05');
    }

    public function test_archives_are_deleted_after_retention_never_below_the_floor_and_never_during_a_legal_hold(): void
    {
        foreach (['2015-03', '2019-03', '2024-03'] as $m) {
            $this->audit("{$m}-10 00:00:00");
            $this->archiver()->archive($m);
        }
        config(['audit_retention.floor_years' => 7, 'audit_retention.archive_years' => 1]);   // 1 year asked, the floor wins
        $hold = DB::table('legal_holds')->insertGetId(['scope_type' => 'case', 'scope_id' => 1, 'reason' => 'litigation', 'placed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame([], $this->archiver()->prune(), 'an active legal hold keeps everything');

        DB::table('legal_holds')->where('id', $hold)->update(['released_at' => now()]);
        $this->assertSame(7, $this->archiver()->retentionYears());
        $this->assertSame(['2015-03', '2019-03'], $this->archiver()->prune());
        Storage::disk('local')->assertMissing($this->prefix('audit-archive/2015-03.jsonl.gz'));
        Storage::disk('local')->assertExists($this->prefix('audit-archive/2024-03.jsonl.gz'));
        $this->assertNotNull(DB::table('audit_archives')->where('month', '2015-03')->value('pruned_at'));

        $this->expectExceptionMessage('deleted at the end of retention');
        $this->archiver()->restore('2015-03');
    }

    public function test_the_command_archives_what_is_due_and_says_so(): void
    {
        $this->online(2);
        $this->audit('2026-07-04 00:00:00');
        $this->audit('2026-08-04 00:00:00');
        $this->audit('2026-10-04 00:00:00');

        $this->artisan('audits:archive --dry-run')->expectsOutputToContain($this->firm().': would archive 2026-07, 2026-08 (online: 2 months)')->assertExitCode(0);
        $this->assertSame(3, DB::table('audits')->count());

        $this->artisan('audits:archive')->expectsOutputToContain($this->firm().': 2026-07 archived (1 rows')->expectsOutputToContain($this->firm().': 2026-08 archived')->assertExitCode(0);
        $this->assertSame(1, DB::table('audits')->count());
        $this->artisan('audits:restore 2026-07')->expectsOutputToContain('1 audit row(s) restored')->assertExitCode(0);
        $this->artisan('audits:restore 2025-01')->assertExitCode(1);
    }

    public function test_every_plan_states_its_online_window(): void
    {
        foreach (config('entitlements.plans') as $name => $plan) {
            $this->assertArrayHasKey('audit_online_months', $plan['limits'], "plan {$name} must say how long audits stay online (0 would archive everything)");
        }
    }

    public function test_on_mysql_a_month_is_removed_by_dropping_its_partition(): void
    {
        if (! app(AuditPartitions::class)->supported()) {
            $this->markTestSkipped('Partitions need MySQL or MariaDB.');
        }
        $this->audit('2026-06-05 00:00:00');
        $this->audit('2026-07-05 00:00:00');
        $partitions = app(AuditPartitions::class);

        try {
            $partitions->enable();
            $this->assertContains('p202606', $partitions->names());
            $this->assertContains('p202612', $partitions->names(), 'two months ahead');
            $this->assertSame('pmax', last($partitions->names()));

            $r = $this->archiver()->archive('2026-06');

            $this->assertSame(['partition', 1], [$r['how'], $r['removed']]);
            $this->assertNotContains('p202606', $partitions->names());
            $this->assertSame(1, DB::table('audits')->count());
            $this->audit('2026-10-20 00:00:00');   // writes keep working
            $this->travelTo(CarbonImmutable::parse('2027-01-10'));
            $this->assertSame(3, $partitions->ensure(), "January, February and March");
            $this->assertSame(0, $partitions->ensure(), "idempotent");
            $this->assertContains('p202703', $partitions->names());
        } finally {
            if ($partitions->enabled()) {
                DB::statement('ALTER TABLE audits REMOVE PARTITIONING');
            }
            \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = false;   // DDL committed: rebuild for the next test
        }
    }
}
