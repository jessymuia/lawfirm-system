<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Court;
use App\Models\Hearing;
use App\Models\HearingType;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DATA-1/DATA-2: courts.type and hearings.hearing_type previously had no
 * FK constraint at the database level, allowing dangling values that
 * silently broke downstream lookups (see the DATA-6 fix for the crashes
 * that resulted). These tests insert directly at the DB/Eloquent layer
 * (bypassing application-level validation) to prove the FK constraint
 * itself — not just the request validation layer — actually rejects an
 * orphaned reference.
 */
class DataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_hearing_with_nonexistent_hearing_type_is_rejected_at_the_database_level(): void
    {
        $this->skipUnlessForeignKeysEnforceable();

        $this->expectException(QueryException::class);

        Hearing::create([
            'case_id' => Cases::factory()->create()->id,
            'court_id' => Court::factory()->create()->id,
            'hearing_date' => now()->format('Y-m-d'),
            'hearing_type' => 999999, // does not exist in hearing_types
            'created_by' => 1,
        ]);
    }

    public function test_court_with_nonexistent_court_type_is_rejected_at_the_database_level(): void
    {
        $this->skipUnlessForeignKeysEnforceable();

        $this->expectException(QueryException::class);

        Court::create([
            'name' => 'Test Court',
            'type' => 999999, // does not exist in court_types
            'created_by' => 1,
        ]);
    }

    public function test_hearing_with_a_real_hearing_type_succeeds(): void
    {
        $hearingType = HearingType::factory()->create();

        $hearing = Hearing::create([
            'case_id' => Cases::factory()->create()->id,
            'court_id' => Court::factory()->create()->id,
            'hearing_date' => now()->format('Y-m-d'),
            'hearing_type' => $hearingType->id,
            'created_by' => 1,
        ]);

        $this->assertNotNull($hearing->id);
    }

    /**
     * SQLite cannot add a foreign key to an existing table (ALTER TABLE
     * ... ADD FOREIGN KEY is unsupported), so the DATA-1/DATA-2 constraint
     * migrations are silently no-ops on the sqlite test database. The
     * FK-rejection tests only prove anything on MySQL — CI's mysql-backed
     * job runs them; the local sqlite run skips them explicitly rather
     * than passing vacuously.
     */
    private function skipUnlessForeignKeysEnforceable(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('FK constraints added via ALTER TABLE do not exist on sqlite; covered by the MySQL CI job.');
        }
    }
}
