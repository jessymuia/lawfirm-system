<?php

namespace Tests\Feature;

use App\Catalog\SystemCatalog;
use App\Catalog\SystemCatalogException;
use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Court;
use App\Models\CourtType;
use App\Models\HearingType;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantMigrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** TEN-11: platform-owned reference catalog vs the firm's own rows. */
class SystemCatalogTest extends TestCase implements \Tests\OwnsTenancy
{
    use CreatesTestUsers, RefreshDatabase;

    private function counts(): array
    {
        return [
            'court_types' => count(SystemCatalog::COURT_TYPES),
            'courts' => count(SystemCatalog::COURTS),
            'hearing_types' => count(SystemCatalog::HEARING_TYPES),
        ];
    }

    private function courtsAdmin()
    {
        return $this->userWithPermissions([
            ModulePermissions::LIST_COURTS, ModulePermissions::CREATE_COURTS, ModulePermissions::UPDATE_COURTS, ModulePermissions::DELETE_COURTS,
            ModulePermissions::LIST_HEARINGS, ModulePermissions::CREATE_HEARINGS, ModulePermissions::UPDATE_HEARINGS, ModulePermissions::DELETE_HEARINGS,
        ]);
    }

    // ---- the catalog definition itself ------------------------------------

    public function test_catalog_definition_is_internally_consistent(): void
    {
        foreach ([SystemCatalog::COURT_TYPES, SystemCatalog::COURTS, SystemCatalog::HEARING_TYPES] as $entries) {
            foreach (array_keys($entries) as $key) {
                $this->assertMatchesRegularExpression('/^[a-z0-9_]{1,64}$/', $key, 'catalog keys are stable identifiers');
            }
            $names = array_map(fn ($e) => mb_strtolower($e['name']), $entries);
            $this->assertSame(count($names), count(array_unique($names)), 'two entries with one name would adopt the same row');
        }
        foreach (SystemCatalog::COURTS as $key => $court) {
            $this->assertArrayHasKey($court['type'], SystemCatalog::COURT_TYPES, "court {$key} points at an unknown court type");
        }
    }

    // ---- sync ---------------------------------------------------------------

    public function test_sync_creates_the_catalog_on_an_empty_database(): void
    {
        // The DATA-1/DATA-2 migrations already insert 'Unclassified'; the sync adopts those rather than duplicating them.
        $report = SystemCatalog::sync();

        $this->assertSame(1, $report['court_types']['adopted']);
        $this->assertSame(count(SystemCatalog::COURT_TYPES) - 1, $report['court_types']['created']);
        $this->assertSame(count(SystemCatalog::COURTS), $report['courts']['created']);
        $this->assertSame(1, $report['hearing_types']['adopted']);
        $this->assertSame(count(SystemCatalog::HEARING_TYPES) - 1, $report['hearing_types']['created']);

        foreach ($this->counts() as $table => $n) {
            $this->assertSame($n, DB::table($table)->where('is_system', true)->count(), $table);
            $this->assertSame($n, DB::table($table)->count(), "{$table}: no duplicates");
        }
        $supreme = Court::where('catalog_key', 'ke_supreme_court')->firstOrFail();
        $this->assertSame('supreme_court', $supreme->courtType->catalog_key);
    }

    public function test_sync_is_idempotent(): void
    {
        SystemCatalog::sync();
        $before = DB::table('court_types')->orderBy('id')->get()->toArray();
        $audits = DB::table('audits')->count();

        $report = SystemCatalog::sync();

        foreach ($report as $table => $c) {
            $this->assertSame(0, $c['created'] + $c['adopted'] + $c['updated'], "{$table} changed on a second run");
        }
        $this->assertEquals($before, DB::table('court_types')->orderBy('id')->get()->toArray());
        $this->assertSame($audits, DB::table('audits')->count(), 'a no-op sync writes no audit rows');
    }

    public function test_sync_adopts_existing_firm_rows_by_name_and_keeps_their_ids(): void
    {
        // A firm seeded before TEN-11: ordinary rows, already referenced by its matters.
        $high = CourtType::create(['name' => 'high court']);   // case differs: still the same entry
        $court = Court::create(['name' => 'Supreme Court of Kenya', 'type' => $high->id]);
        $case = Cases::factory()->create(['court_id' => $court->id]);
        $trial = HearingType::create(['name' => 'Trial']);
        $trial->delete();   // even a deleted one is brought back rather than duplicated

        $report = SystemCatalog::sync();

        $this->assertGreaterThanOrEqual(2, $report['court_types']['adopted']);
        $this->assertSame(1, $report['courts']['adopted']);
        $this->assertSame(2, $report['hearing_types']['adopted'], 'Trial + the migration-seeded Unclassified');

        $high->refresh();
        $this->assertTrue($high->is_system);
        $this->assertSame('high_court', $high->catalog_key);
        $this->assertSame('High Court', $high->name, 'the official name wins');

        $court->refresh();
        $this->assertSame('ke_supreme_court', $court->catalog_key);
        $this->assertSame(CourtType::where('catalog_key', 'supreme_court')->value('id'), $court->type, 'the catalog decides the court type');
        $this->assertSame($court->id, $case->fresh()->court_id, 'matters still point at the same row');

        $trial = HearingType::withTrashed()->find($trial->id);
        $this->assertFalse($trial->trashed());
        $this->assertSame('trial', $trial->catalog_key);
        $this->assertSame(1, HearingType::where('name', 'Trial')->count());
    }

    public function test_sync_repairs_a_tampered_system_row_but_keeps_the_firms_alias(): void
    {
        SystemCatalog::sync();
        $row = CourtType::where('catalog_key', 'high_court')->firstOrFail();
        // Someone edited the database directly (bypassing the model guard).
        DB::table('court_types')->where('id', $row->id)->update(['name' => 'Hacked', 'alias' => 'HC', 'deleted_at' => now()]);

        $report = SystemCatalog::sync();

        $this->assertSame(1, $report['court_types']['updated']);
        $row = CourtType::find($row->id);
        $this->assertNotNull($row, 'restored');
        $this->assertSame('High Court', $row->name);
        $this->assertSame('HC', $row->alias, 'the overlay is the firm\'s and survives every sync');
    }

    public function test_dry_run_reports_without_writing(): void
    {
        $report = SystemCatalog::sync(dryRun: true);

        $this->assertSame(count(SystemCatalog::COURTS), $report['courts']['created']);
        $this->assertSame(0, DB::table('courts')->count());
        $this->assertSame(0, DB::table('court_types')->where('is_system', true)->count());
    }

    public function test_sync_is_skipped_until_the_schema_is_migrated(): void
    {
        // A version-lagging self-hosted install can run new code before migrating.
        Schema::table('hearing_types', function ($t) {
            $t->dropUnique(['catalog_key']);
            $t->dropColumn(['catalog_key', 'is_system', 'alias']);
        });

        $this->assertFalse(SystemCatalog::installed());
        $this->assertNull(SystemCatalog::sync());
        $this->assertSame(0, DB::table('courts')->count(), 'nothing half-applied');
    }

    // ---- the model guard ----------------------------------------------------

    public function test_system_rows_cannot_be_renamed_or_deleted_from_any_entry_point(): void
    {
        SystemCatalog::sync();
        $row = HearingType::where('catalog_key', 'trial')->firstOrFail();

        try {
            $row->update(['name' => 'Renamed']);
            $this->fail('rename allowed');
        } catch (SystemCatalogException) {
        }
        try {
            $row->fresh()->delete();
            $this->fail('delete allowed');
        } catch (SystemCatalogException) {
        }
        try {
            $row->fresh()->forceDelete();
            $this->fail('force delete allowed');
        } catch (SystemCatalogException) {
        }

        $this->assertSame('Trial', $row->fresh()->name);
        $this->assertFalse($row->fresh()->trashed());

        $row = $row->fresh();
        $row->alias = 'Full hearing';
        $row->save();
        $this->assertSame('Full hearing', $row->fresh()->displayName());
    }

    public function test_a_firm_cannot_forge_or_promote_a_system_row(): void
    {
        $this->expectException(SystemCatalogException::class);
        $mine = HearingType::create(['name' => 'Ours']);
        $mine->forceFill(['is_system' => true, 'catalog_key' => 'trial'])->save();
    }

    public function test_a_firm_cannot_create_a_row_flagged_as_system(): void
    {
        $this->expectException(SystemCatalogException::class);
        (new CourtType)->forceFill(['name' => 'Fake', 'is_system' => true])->save();
    }

    public function test_catalog_columns_are_not_mass_assignable(): void
    {
        $row = CourtType::create(['name' => 'Ours', 'is_system' => true, 'catalog_key' => 'x']);

        $this->assertFalse($row->fresh()->is_system);
        $this->assertNull($row->fresh()->catalog_key);
    }

    // ---- the API ------------------------------------------------------------

    public function test_api_lists_system_and_firm_rows_with_ownership_and_display_name(): void
    {
        SystemCatalog::sync();
        CourtType::where('catalog_key', 'high_court')->firstOrFail()->forceFill(['alias' => 'HC'])->save();
        CourtType::create(['name' => 'County Tribunal']);

        $rows = collect($this->actingAs($this->courtsAdmin())->getJson('/api/v1/courttypes')->assertOk()->json('data.data'));

        $hc = $rows->firstWhere('official_name', 'High Court');
        $this->assertSame('HC', $hc['name']);
        $this->assertTrue($hc['is_system']);
        $mine = $rows->firstWhere('name', 'County Tribunal');
        $this->assertFalse($mine['is_system']);
        $this->assertNull($mine['alias']);

        // the alias is searchable
        $found = $this->getJson('/api/v1/courttypes?page=1&search=HC')->assertOk()->json('data.data');
        $this->assertSame(['HC'], array_column($found, 'name'));
    }

    public function test_api_refuses_to_rename_or_delete_a_system_row(): void
    {
        SystemCatalog::sync();
        $this->actingAs($this->courtsAdmin());
        $type = CourtType::where('catalog_key', 'high_court')->firstOrFail();
        $court = Court::where('catalog_key', 'ke_supreme_court')->firstOrFail();
        $hearing = HearingType::where('catalog_key', 'trial')->firstOrFail();

        $this->putJson("/api/v1/courttypes/{$type->id}", ['name' => 'Renamed'])->assertStatus(409)->assertJsonPath('code', 'system_catalog');
        $this->putJson("/api/v1/courts/{$court->id}", ['name' => 'Renamed', 'type' => $type->id])->assertStatus(409);
        $this->putJson("/api/v1/hearingtypes/{$hearing->id}", ['name' => 'Renamed'])->assertStatus(409);
        $this->deleteJson("/api/v1/courttypes/{$type->id}")->assertStatus(409)->assertJsonPath('code', 'system_catalog');
        $this->deleteJson("/api/v1/courts/{$court->id}")->assertStatus(409);
        $this->deleteJson("/api/v1/hearingtypes/{$hearing->id}")->assertStatus(409);

        $this->assertSame('High Court', $type->fresh()->name);
        $this->assertNull($type->fresh()->deleted_by);
        $this->assertFalse($court->fresh()->trashed());
        $this->assertFalse($hearing->fresh()->trashed());
    }

    public function test_api_sets_and_clears_a_local_alias_on_a_system_row(): void
    {
        SystemCatalog::sync();
        $this->actingAs($this->courtsAdmin());
        $court = Court::where('catalog_key', 'ke_high_court_nairobi_milimani')->firstOrFail();

        $this->putJson("/api/v1/courts/{$court->id}", ['alias' => 'Milimani'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Milimani')
            ->assertJsonPath('data.official_name', 'High Court at Nairobi (Milimani Law Courts)')
            ->assertJsonPath('data.court_type', 'High Court');

        // an alias can't impersonate another entry
        $this->putJson("/api/v1/courts/{$court->id}", ['alias' => 'supreme court of kenya'])->assertStatus(422);

        $this->putJson("/api/v1/courts/{$court->id}", ['alias' => ''])->assertOk()->assertJsonPath('data.alias', null);
        $this->assertSame('High Court at Nairobi (Milimani Law Courts)', $court->fresh()->displayName());
    }

    public function test_the_alias_is_what_the_firm_sees_on_matters(): void
    {
        SystemCatalog::sync();
        $court = Court::where('catalog_key', 'ke_elc_nairobi')->firstOrFail();
        $court->forceFill(['alias' => 'ELC Milimani'])->save();
        Cases::factory()->create(['court_id' => $court->id]);

        $admin = $this->adminUser();
        $rows = $this->actingAs($admin)->getJson('/api/v1/cases')->assertOk()->json('data.data');

        $this->assertSame('ELC Milimani', $rows[0]['court']);
    }

    public function test_firms_keep_full_control_of_their_own_rows(): void
    {
        SystemCatalog::sync();
        $this->actingAs($this->courtsAdmin());

        $id = $this->postJson('/api/v1/courttypes', ['name' => 'Rent Tribunal'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/courttypes/{$id}", ['name' => 'Rent Restriction Tribunal'])->assertOk()->assertJsonPath('data.name', 'Rent Restriction Tribunal');
        $this->deleteJson("/api/v1/courttypes/{$id}")->assertOk();

        // and a firm cannot reuse a catalog name for a row of its own
        $this->postJson('/api/v1/courttypes', ['name' => 'High Court'])->assertStatus(422);
    }

    public function test_catalog_sync_command(): void
    {
        $this->assertSame(0, Artisan::call('catalog:sync', ['--dry-run' => true]));
        $this->assertStringContainsString('[dry run]', Artisan::output());
        $this->assertSame(0, DB::table('courts')->count());

        $this->assertSame(0, Artisan::call('catalog:sync'));
        $this->assertSame(count(SystemCatalog::COURTS), DB::table('courts')->where('is_system', true)->count());
    }

    public function test_app_upgrade_resyncs_the_catalog(): void
    {
        $this->assertSame(0, Artisan::call('app:upgrade', ['--skip-cache' => true]));

        $this->assertSame(count(SystemCatalog::HEARING_TYPES), DB::table('hearing_types')->where('is_system', true)->count());
    }

    // ---- multi-tenant -------------------------------------------------------

    public function test_every_provisioned_firm_gets_the_catalog_and_aliases_stay_per_firm(): void
    {
        $original = config('database.default');
        $dir = sys_get_temp_dir().'/catalog-'.uniqid();
        mkdir($dir);
        touch("{$dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.storage_disks' => ['local'],
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        try {
            $tenants = [];
            foreach (['firm-a', 'firm-b'] as $slug) {
                $tenants[$slug] = app(TenantLifecycle::class)->provision([
                    'slug' => $slug, 'name' => $slug, 'admin_email' => "admin@{$slug}.test", 'database' => "{$dir}/{$slug}.sqlite",
                ])['tenant'];
            }
            $in = fn (Tenant $t, \Closure $fn) => app(TenantManager::class)->runIn($t, $fn, allowInactive: true);

            foreach ($tenants as $slug => $t) {
                $in($t, function () use ($slug) {
                    foreach ($this->counts() as $table => $n) {
                        $this->assertSame($n, DB::table($table)->where('is_system', true)->count(), "{$slug}.{$table}");
                    }
                    $this->assertSame(1, DB::table('expense_categories')->where('name', 'Litigation Expenses')->count(), 'starter categories are still seeded');
                });
            }

            $in($tenants['firm-a'], fn () => CourtType::where('catalog_key', 'high_court')->firstOrFail()->forceFill(['alias' => 'HC (A)'])->save());

            // A release adds an entry: re-running the migrator (what app:upgrade does per tenant) reaches every firm.
            $in($tenants['firm-b'], fn () => DB::table('hearing_types')->where('catalog_key', 'mention')->delete());
            foreach ($tenants as $t) {
                $this->assertTrue(app(TenantMigrator::class)->migrate($t->fresh()));
            }

            $this->assertSame('HC (A)', $in($tenants['firm-a'], fn () => CourtType::where('catalog_key', 'high_court')->value('alias')));
            $this->assertNull($in($tenants['firm-b'], fn () => CourtType::where('catalog_key', 'high_court')->value('alias')), 'one firm\'s alias never reaches another');
            $this->assertSame(1, $in($tenants['firm-b'], fn () => HearingType::where('catalog_key', 'mention')->count()));
        } finally {
            DB::setDefaultConnection($original);
            app(TenantContext::class)->activate('default');
        }
    }
}
