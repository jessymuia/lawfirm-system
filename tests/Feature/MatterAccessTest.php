<?php

namespace Tests\Feature;

use App\Matters\MatterAccess;
use App\Models\Cases;
use App\Models\Hearing;
use App\Models\HearingType;
use App\Models\Invoice;
use App\Models\MatterAccessGrant;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-10: matter-level access control. */
class MatterAccessTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $alice;   // assigned to $mine

    private User $bob;     // unrelated

    private Cases $mine;

    private Cases $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]); // owen-it skips auditing while running in console (i.e. tests) by default
        $perms = ['list-cases', 'update-cases', 'delete-cases', 'list-hearings', 'create-hearings', 'update-hearings', 'list-time-entries', 'list-invoices', 'view-dashboard', 'export-firm-data'];
        $this->alice = $this->userWithPermissions($perms);
        $this->bob = $this->userWithPermissions(array_merge($perms, ['manage-matter-access']));

        $this->mine = Cases::factory()->create(['assigned_to' => $this->alice->id, 'case_number' => 'MINE-1', 'description' => 'zebra matter']);
        $this->theirs = Cases::factory()->create(['assigned_to' => $this->bob->id, 'case_number' => 'THEIRS-1', 'description' => 'zebra secret']);
    }

    private function restrict(): void
    {
        config(['matters.access' => 'restricted']);
    }

    private function caseNumbers(User $u): array
    {
        $r = $this->actingAs($u)->getJson('/api/v1/cases?per_page=100')->assertOk();
        $rows = $r->json('data.data') ?? $r->json('data') ?? [];

        return collect($rows)->pluck('case_number')->sort()->values()->all();
    }

    public function test_firm_mode_is_the_default_and_changes_nothing(): void
    {
        $this->assertSame('firm', config('matters.access'));
        $this->assertSame(['MINE-1', 'THEIRS-1'], $this->caseNumbers($this->alice));
    }

    public function test_restricted_mode_lists_only_matters_the_user_is_on(): void
    {
        $this->restrict();

        $this->assertSame(['MINE-1'], $this->caseNumbers($this->alice));
        $this->assertSame(['THEIRS-1'], $this->caseNumbers($this->bob));
    }

    public function test_hidden_matter_is_a_404_for_show_update_and_delete_not_a_403(): void
    {
        $this->restrict();
        $this->actingAs($this->alice);

        $this->getJson("/api/v1/cases/{$this->theirs->id}")->assertNotFound();
        $this->putJson("/api/v1/cases/{$this->theirs->id}", ['description' => 'x'])->assertNotFound();
        $this->deleteJson("/api/v1/cases/{$this->theirs->id}")->assertNotFound();
        $this->getJson("/api/v1/cases/{$this->theirs->id}/activity")->assertNotFound();
        $this->getJson("/api/v1/cases/{$this->mine->id}")->assertOk();
        $this->assertNull($this->theirs->fresh()->deleted_at, 'the hidden matter was not touched');
    }

    public function test_creator_and_team_members_keep_access(): void
    {
        $this->restrict();
        $carol = $this->userWithPermissions(['list-cases']);

        $this->assertSame([], $this->caseNumbers($carol));

        DB::table('attorneys')->insert(['case_id' => $this->mine->id, 'advocate_id' => $carol->id, 'role' => 'junior', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['MINE-1'], $this->caseNumbers($carol), 'team member');

        $dave = $this->userWithPermissions(['list-cases', 'create-cases']);
        $this->actingAs($dave);
        $made = Cases::create(['case_number' => 'DAVE-1', 'description' => 'd', 'client_id' => $this->mine->client_id, 'assigned_to' => $this->alice->id, 'start_date' => now(), 'case_type' => 'civil', 'police_station' => 'x', 'opposing_party' => 'x', 'court_id' => $this->mine->court_id, 'lifecycle_status' => 'open']);
        $this->assertSame($dave->id, $made->created_by);
        $this->assertSame(['DAVE-1'], $this->caseNumbers($dave), 'creator keeps access to what they created');
    }

    public function test_access_all_matters_permission_sees_everything(): void
    {
        $this->restrict();
        $partner = $this->userWithPermissions(['list-cases', 'access-all-matters']);

        $this->assertSame(['MINE-1', 'THEIRS-1'], $this->caseNumbers($partner));
    }

    public function test_admin_role_gets_the_new_permissions(): void
    {
        $this->restrict();
        $admin = $this->adminUser();

        $this->assertTrue($admin->checkPermissionTo('access-all-matters'));
        $this->assertSame(['MINE-1', 'THEIRS-1'], $this->caseNumbers($admin));
    }

    public function test_search_export_and_dashboard_do_not_leak_hidden_matters(): void
    {
        $this->restrict();
        $this->actingAs($this->alice);

        $search = $this->getJson('/api/v1/search?q=zebra')->assertOk();
        $titles = collect($search->json('data.cases') ?? $search->json('cases') ?? [])->pluck('title')->all();
        $this->assertSame(['MINE-1'], $titles);

        $csv = $this->get('/api/v1/export/cases')->assertOk()->streamedContent();
        $this->assertStringContainsString('MINE-1', $csv);
        $this->assertStringNotContainsString('THEIRS-1', $csv);

        $this->assertSame(1, Cases::count());
        $this->assertSame(2, Cases::withoutGlobalScopes()->count());
    }

    public function test_full_firm_export_is_refused_to_a_restricted_user(): void
    {
        $this->restrict();
        $this->actingAs($this->alice)->get('/api/v1/export/firm-data')->assertForbidden();
    }

    public function test_records_hanging_off_a_hidden_matter_are_hidden_too(): void
    {
        $court = $this->mine->court_id;
        $h1 = Hearing::factory()->create(['case_id' => $this->mine->id]);
        $h2 = Hearing::factory()->create(['case_id' => $this->theirs->id]);
        DB::table('invoices')->insert(['case_id' => $this->theirs->id, 'client_id' => $this->theirs->client_id, 'invoice_number' => 'INV-X1', 'invoice_date' => now(), 'invoice_due_date' => now(), 'workflow_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $invoiceId = DB::table('invoices')->value('id');
        DB::table('payments')->insert(['invoice_id' => $invoiceId, 'receipt_number' => 'RCT-X1', 'amount' => 10, 'payment_date' => now(), 'method' => 'cash', 'received_by' => $this->bob->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->restrict();
        $this->actingAs($this->alice);

        $this->assertSame([$h1->id], Hearing::pluck('id')->all());
        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count(), 'payments are hidden through their invoice');
        $before = Hearing::withoutGlobalScopes()->find($h2->id)->notes;
        $put = $this->putJson("/api/v1/hearings/{$h2->id}", ['notes' => 'tampered', 'case_id' => $this->theirs->id, 'court_id' => $court, 'hearing_date' => now()->addDay()->format('d/m/Y H:i'), 'hearing_type' => $h2->hearing_type]);
        $this->assertContains($put->status(), [403, 404, 422], $put->getContent());
        $this->assertSame($before, Hearing::withoutGlobalScopes()->find($h2->id)->notes, 'the hidden hearing is untouched');
        $this->assertSame(1, DB::table('payments')->count(), 'rows still exist; they are only hidden');
    }

    public function test_writes_against_a_hidden_matter_are_refused(): void
    {
        $this->restrict();
        $this->actingAs($this->alice);

        $this->expectException(AuthorizationException::class);
        Hearing::factory()->create(['case_id' => $this->theirs->id]);
    }

    public function test_moving_a_record_into_a_hidden_matter_is_refused(): void
    {
        $hearing = Hearing::factory()->create(['case_id' => $this->mine->id]);
        $this->restrict();
        $this->actingAs($this->alice);

        try {
            $hearing->update(['case_id' => $this->theirs->id]);
            $this->fail('expected AuthorizationException');
        } catch (AuthorizationException) {
            $this->assertSame($this->mine->id, $hearing->fresh()->case_id);
        }
    }

    public function test_api_write_against_a_hidden_matter_is_a_403(): void
    {
        $this->restrict();
        $r = $this->actingAs($this->alice)->postJson('/api/v1/hearings', [
            'case_id' => $this->theirs->id, 'court_id' => $this->mine->court_id,
            'hearing_date' => now()->addDay()->format('d/m/Y H:i'), 'hearing_type' => HearingType::factory()->create()->id,
        ]);

        $this->assertContains($r->status(), [403, 422], 'must not succeed: '.$r->getContent());
        $this->assertSame(0, Hearing::withoutGlobalScopes()->where('case_id', $this->theirs->id)->count());
    }

    public function test_explicit_grant_gives_access_and_revoking_removes_it_and_both_are_audited(): void
    {
        $this->restrict();
        $r = $this->actingAs($this->bob)->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => $this->alice->id, 'reason' => 'second chair'])->assertCreated();
        $grantId = $r->json('data.id');

        $this->assertSame(['MINE-1', 'THEIRS-1'], $this->caseNumbers($this->alice));

        $list = $this->actingAs($this->bob)->getJson("/api/v1/cases/{$this->theirs->id}/access")->assertOk();
        $this->assertSame([$this->alice->id], collect($list->json('data.grants'))->pluck('user_id')->all());

        $this->actingAs($this->bob)->deleteJson("/api/v1/cases/{$this->theirs->id}/access/{$grantId}")->assertOk();
        $this->assertSame(['MINE-1'], $this->caseNumbers($this->alice));

        $events = Audit::where('auditable_type', MatterAccessGrant::class)->where('auditable_id', $grantId)->pluck('event')->all();
        $this->assertContains('created', $events);
        $this->assertContains('deleted', $events);
        $this->assertSame($this->bob->id, Audit::where('auditable_type', MatterAccessGrant::class)->where('event', 'created')->value('user_id'), 'the audit names who granted');
    }

    public function test_regranting_does_not_stack_rows_and_an_expired_grant_stops_working(): void
    {
        $this->restrict();
        $this->actingAs($this->bob);
        $this->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => $this->alice->id])->assertCreated();
        $this->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => $this->alice->id, 'reason' => 'again'])->assertCreated();
        $this->assertSame(1, MatterAccessGrant::count());

        $this->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => $this->alice->id, 'expires_at' => now()->addHour()->toDateTimeString()])->assertCreated();
        $this->assertSame(['MINE-1', 'THEIRS-1'], $this->caseNumbers($this->alice));

        $this->travel(2)->hours();
        $this->assertSame(['MINE-1'], $this->caseNumbers($this->alice), 'time-boxed grant has lapsed');
        $this->travelBack();
    }

    public function test_grant_endpoint_guards(): void
    {
        $this->restrict();
        // no manage-matter-access
        $this->actingAs($this->alice)->postJson("/api/v1/cases/{$this->mine->id}/access", ['user_id' => $this->bob->id])->assertForbidden();
        // manager who cannot see the matter cannot manage it, and cannot learn it exists
        $mgr = $this->userWithPermissions(['manage-matter-access']);
        $this->actingAs($mgr)->postJson("/api/v1/cases/{$this->mine->id}/access", ['user_id' => $mgr->id])->assertNotFound();
        $this->actingAs($mgr)->getJson("/api/v1/cases/{$this->mine->id}/access")->assertNotFound();
        // validation
        $this->actingAs($this->bob)->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => 999999])->assertStatus(422);
        $this->actingAs($this->bob)->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => $this->alice->id, 'expires_at' => '2001-01-01'])->assertStatus(422);
        // a grant on one matter cannot be revoked through another
        $g = MatterAccessGrant::create(['case_id' => $this->theirs->id, 'user_id' => $this->alice->id]);
        $this->actingAs($this->alice)->deleteJson("/api/v1/cases/{$this->mine->id}/access/{$g->id}")->assertForbidden();
        $this->actingAs($this->bob)->deleteJson("/api/v1/cases/{$this->theirs->id}/access/999999")->assertNotFound();
    }

    public function test_console_and_queue_contexts_without_a_user_are_not_restricted(): void
    {
        $this->restrict();
        auth()->logout();
        $this->assertNull(MatterAccess::subject());
        $this->assertSame(2, Cases::count());
    }

    public function test_the_firm_mode_grant_endpoints_still_work_but_filter_nothing(): void
    {
        $this->actingAs($this->bob)->postJson("/api/v1/cases/{$this->theirs->id}/access", ['user_id' => $this->alice->id])->assertCreated();
        $this->assertSame(['MINE-1', 'THEIRS-1'], $this->caseNumbers($this->alice));
    }
}
