<?php

namespace Tests\Feature;

use App\Compliance\LegalHoldException;
use App\Compliance\LegalHolds;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Models\EthicalScreen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-13: legal holds, retention schedule, data-subject requests. */
class ComplianceTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private User $officer;

    private User $lawyer;

    private Client $acme;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true, 'filesystems.default' => 'local']);
        Storage::fake('local');
        $this->officer = $this->userWithPermissions(['manage-compliance']);
        $this->lawyer = $this->userWithPermissions(['delete-documents', 'delete-clients', 'delete-cases', 'list-clients', 'list-cases']);
        $this->acme = Client::factory()->create(['name' => 'Acme Ltd', 'phone_number' => '0700111222', 'address' => '1 Moi Avenue']);
    }

    private function matter(?Client $c = null, string $status = 'closed', ?string $end = '2010-01-01'): Cases
    {
        return Cases::factory()->create(['client_id' => ($c ?? $this->acme)->id, 'lifecycle_status' => $status, 'end_date' => $end, 'description' => 'privileged advice summary']);
    }

    private function doc(Cases $case, string $name = 'a.txt', bool $withFile = true): Document
    {
        $path = "tenants/default/cases/docs/{$name}";
        $withFile && Storage::disk('local')->put($path, 'content '.$name);

        return Document::create(['case_id' => $case->id, 'title' => $name, 'filename' => $name, 'filepath' => $path, 'full_path' => $path, 'disk' => 'local', 'mimetype' => 'text/plain', 'filesize' => 10, 'extension' => 'txt', 'version' => 1, 'is_current' => true]);
    }

    private function hold(string $type, int $id, string $reason = 'litigation pending')
    {
        return $this->actingAs($this->officer)->postJson('/api/v1/compliance/holds', ['scope_type' => $type, 'scope_id' => $id, 'reason' => $reason]);
    }

    private function policy(int $years = 7, bool $auto = true)
    {
        return $this->actingAs($this->officer)->putJson('/api/v1/compliance/retention/case_documents', ['retain_years' => $years, 'auto_purge' => $auto]);
    }

    // ---- permissions -----------------------------------------------------------------------------------------------

    public function test_every_compliance_route_needs_the_permission(): void
    {
        $nobody = $this->userWithPermissions(['list-clients']);
        foreach ([['get', '/holds'], ['post', '/holds'], ['post', '/holds/1/release'], ['get', '/retention'], ['get', '/retention/due'], ['put', '/retention/case_documents'],
            ['get', '/dsar'], ['post', '/dsar'], ['get', '/dsar/1'], ['post', '/dsar/1/verify'], ['post', '/dsar/1/reject'], ['post', '/dsar/1/export'], ['post', '/dsar/1/erase']] as [$m, $p]) {
            $this->actingAs($nobody)->json($m, '/api/v1/compliance'.$p, [])->assertForbidden();
        }
    }

    // ---- legal holds -----------------------------------------------------------------------------------------------

    public function test_a_hold_blocks_deleting_the_client_the_matter_and_its_documents(): void
    {
        $case = $this->matter();
        $doc = $this->doc($case);
        $this->hold('client', $this->acme->id)->assertCreated();

        $this->actingAs($this->lawyer)->deleteJson('/api/v1/documents/'.$doc->id)->assertStatus(423)->assertJsonPath('code', 'legal_hold');
        $this->actingAs($this->lawyer)->deleteJson('/api/v1/clients/'.$this->acme->id)->assertStatus(423);
        $this->actingAs($this->lawyer)->deleteJson('/api/v1/cases/'.$case->id)->assertStatus(423);
        $this->assertNull(Document::withTrashed()->find($doc->id)->deleted_at);
        $this->assertNull(Client::withTrashed()->find($this->acme->id)->deleted_at);
        $this->assertNull(Cases::withTrashed()->find($case->id)->deleted_at);

        // an attempt is itself recorded
        $this->assertGreaterThan(0, DB::table('audits')->where('event', 'legal_hold_blocked_delete')->count());
    }

    public function test_the_bulk_delete_paths_are_blocked_too(): void
    {
        $case = $this->matter();
        $this->hold('client', $this->acme->id)->assertCreated();
        $this->actingAs($this->lawyer)->postJson('/api/v1/clients/bulk-delete', ['ids' => [$this->acme->id]])->assertStatus(423);
        $this->actingAs($this->lawyer)->postJson('/api/v1/cases/bulk-delete', ['ids' => [$case->id]])->assertStatus(423);
        $this->assertNull(Client::withTrashed()->find($this->acme->id)->deleted_at);
        $this->assertNull(Cases::withTrashed()->find($case->id)->deleted_at);

        // a mixed batch is refused as a whole, not half-applied
        $free = Client::factory()->create();
        $this->actingAs($this->lawyer)->postJson('/api/v1/clients/bulk-delete', ['ids' => [$free->id, $this->acme->id]])->assertStatus(423);
        $this->assertNull(Client::withTrashed()->find($free->id)->deleted_at);
    }

    public function test_hard_deletion_is_blocked_as_well(): void
    {
        $case = $this->matter();
        $this->hold('case', $case->id)->assertCreated();
        $this->expectException(LegalHoldException::class);
        $case->forceDelete();
    }

    public function test_a_matter_hold_covers_only_that_matter(): void
    {
        $held = $this->matter();
        $free = $this->matter();
        $dHeld = $this->doc($held, 'h.txt');
        $dFree = $this->doc($free, 'f.txt');
        $this->hold('case', $held->id)->assertCreated();

        $this->actingAs($this->lawyer)->deleteJson('/api/v1/documents/'.$dHeld->id)->assertStatus(423);
        $this->actingAs($this->lawyer)->deleteJson('/api/v1/documents/'.$dFree->id)->assertOk();
    }

    public function test_releasing_needs_a_reason_is_audited_and_lifts_the_block(): void
    {
        $case = $this->matter();
        $doc = $this->doc($case);
        $id = $this->hold('case', $case->id)->json('data.id');
        $this->hold('case', $case->id)->assertStatus(409);                       // no second hold on the same matter

        $this->actingAs($this->officer)->postJson("/api/v1/compliance/holds/{$id}/release", ['reason' => ''])->assertStatus(422);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/holds/{$id}/release", ['reason' => 'matter settled'])->assertOk()->assertJsonPath('data.release_reason', 'matter settled');
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/holds/{$id}/release", ['reason' => 'again'])->assertStatus(409);

        $this->actingAs($this->lawyer)->deleteJson('/api/v1/documents/'.$doc->id)->assertOk();
        $this->assertSame(1, DB::table('audits')->where('event', 'legal_hold_placed')->count());
        $this->assertSame(1, DB::table('audits')->where('event', 'legal_hold_released')->count());
        $this->assertSame(1, DB::table('legal_holds')->count());                  // history is kept
    }

    public function test_a_hold_needs_a_reason_and_a_real_target(): void
    {
        $this->hold('case', 999999)->assertStatus(404);
        $this->actingAs($this->officer)->postJson('/api/v1/compliance/holds', ['scope_type' => 'client', 'scope_id' => $this->acme->id, 'reason' => ''])->assertStatus(422);
        $this->actingAs($this->officer)->postJson('/api/v1/compliance/holds', ['scope_type' => 'invoice', 'scope_id' => 1, 'reason' => 'x'])->assertStatus(422);
    }

    public function test_someone_walled_off_from_a_client_cannot_place_a_hold_on_it(): void
    {
        EthicalScreen::create(['user_id' => $this->officer->id, 'client_id' => $this->acme->id, 'reason' => 'conflict']);
        $this->hold('client', $this->acme->id)->assertStatus(404);
    }

    // ---- retention -------------------------------------------------------------------------------------------------

    public function test_the_retention_period_is_validated_and_only_documents_are_purgeable(): void
    {
        $this->policy(0)->assertStatus(422);
        config(['compliance.minimum_retention_years' => 5]);
        $this->policy(3)->assertStatus(422);
        $this->policy(5, false)->assertOk();
        config(['compliance.minimum_retention_years' => 1]);
        $this->actingAs($this->officer)->putJson('/api/v1/compliance/retention/financial_records', ['retain_years' => 7])->assertStatus(422);
        $this->actingAs($this->officer)->putJson('/api/v1/compliance/retention/audit_log', ['retain_years' => 7])->assertStatus(422);
        $this->policy(7, false)->assertOk()->assertJsonPath('data.retain_years', 7);
        $classes = collect($this->actingAs($this->officer)->getJson('/api/v1/compliance/retention')->json('data.classes'))->keyBy('record_class');
        $this->assertTrue($classes['case_documents']['purgeable']);
        $this->assertFalse($classes['financial_records']['purgeable']);
        $this->assertSame(2, DB::table('audits')->where('event', 'retention_policy_changed')->count());
    }

    public function test_nothing_is_due_without_a_policy(): void
    {
        $this->doc($this->matter());
        $this->artisan('retention:run --execute')->assertSuccessful();
        $this->assertSame(0, DB::table('retention_disposals')->count());
        $this->assertTrue(Storage::disk('local')->exists('tenants/default/cases/docs/a.txt'));
    }

    public function test_only_documents_of_closed_matters_past_the_period_are_due(): void
    {
        $old = $this->doc($this->matter(null, 'closed', now()->subYears(8)->toDateString()), 'old.txt');
        $this->doc($this->matter(null, 'closed', now()->subYears(2)->toDateString()), 'recent.txt');
        $this->doc($this->matter(null, 'open', now()->subYears(20)->toDateString()), 'open.txt');
        $this->doc($this->matter(null, 'settled', null), 'noenddate.txt');
        $settled = $this->doc($this->matter(null, 'settled', now()->subYears(9)->toDateString()), 'settled.txt');
        $this->policy(7, true);

        $due = $this->actingAs($this->officer)->getJson('/api/v1/compliance/retention/due')->assertOk();
        $this->assertSame(2, $due->json('data.due_count'));
        $this->assertEqualsCanonicalizing([$old->id, $settled->id], $due->json('data.document_ids'));
    }

    public function test_a_dry_run_changes_nothing_and_execute_disposes_only_when_auto_purge_is_on(): void
    {
        $d = $this->doc($this->matter(), 'old.txt');
        $this->policy(7, false);
        $this->artisan('retention:run --execute')->assertSuccessful();    // auto-purge off: report only
        $this->artisan('retention:run')->assertSuccessful();
        $this->assertTrue(Storage::disk('local')->exists($d->full_path));
        $this->assertSame(0, DB::table('retention_disposals')->count());

        $this->policy(7, true);
        $this->artisan('retention:run')->assertSuccessful();               // still a dry run without --execute
        $this->assertTrue(Storage::disk('local')->exists($d->full_path));

        $this->artisan('retention:run --execute')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($d->full_path));
        $row = DB::table('retention_disposals')->where('document_id', $d->id)->first();
        $this->assertNotNull($row);
        $this->assertSame(7, (int) $row->retain_years);
        $this->assertNotNull(Document::withTrashed()->find($d->id)->deleted_at);     // the stub stays
        $this->assertSame(1, DB::table('audits')->where('event', 'retention_disposed')->count());

        $this->artisan('retention:run --execute')->assertSuccessful();     // idempotent
        $this->assertSame(1, DB::table('retention_disposals')->count());
    }

    public function test_held_matters_are_never_disposed_of(): void
    {
        $case = $this->matter();
        $d = $this->doc($case, 'held.txt');
        $free = $this->doc($this->matter(), 'free.txt');
        $this->policy(7, true);
        $this->hold('client', $this->acme->id)->assertCreated();       // covers both matters (same client)
        $other = Client::factory()->create();
        $d3 = $this->doc($this->matter($other), 'other.txt');

        $this->artisan('retention:run --execute')->assertSuccessful();
        $this->assertTrue(Storage::disk('local')->exists($d->full_path));
        $this->assertTrue(Storage::disk('local')->exists($free->full_path));
        $this->assertFalse(Storage::disk('local')->exists($d3->full_path));
        $due = $this->actingAs($this->officer)->getJson('/api/v1/compliance/retention/due')->json('data');
        $this->assertSame(0, $due['due_count']);
        $this->assertSame(2, $due['held_count']);
    }

    public function test_an_already_deleted_document_is_still_disposed_of_when_due(): void
    {
        $d = $this->doc($this->matter(), 'gone.txt');
        $d->delete();   // soft-deleted by a user long ago; the bytes are still on disk
        $this->policy(7, true);
        $this->artisan('retention:run --execute')->assertSuccessful();
        $this->assertFalse(Storage::disk('local')->exists($d->full_path));
    }

    public function test_an_export_does_not_count_disposed_files_as_lost(): void
    {
        $d = $this->doc($this->matter(), 'disposed.txt');
        $keep = $this->doc($this->matter(null, 'open', null), 'keep.txt');
        $this->policy(7, true);
        $this->artisan('retention:run --execute')->assertSuccessful();
        Storage::disk('local')->delete($keep->full_path);   // a genuinely lost file

        $dir = sys_get_temp_dir().'/cmp-'.uniqid();
        $this->artisan('portability:export', ['--output' => $dir] + $this->firmOption())->assertSuccessful();
        $m = json_decode(file_get_contents($dir.'/manifest.json'), true);
        $this->assertSame(1, $m['files']['missing']);       // only the lost one
    }

    // ---- data-subject requests -------------------------------------------------------------------------------------

    private function request(string $type = 'access', ?Client $c = null)
    {
        return $this->actingAs($this->officer)->postJson('/api/v1/compliance/dsar', ['type' => $type, 'client_id' => ($c ?? $this->acme)->id, 'requester_name' => 'Jane Acme', 'requester_email' => 'jane@acme.test', 'received_at' => '2026-09-01']);
    }

    private function verify(int $id)
    {
        return $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/verify", ['method' => 'id_document', 'note' => 'National ID sighted at reception']);
    }

    public function test_a_request_starts_the_clock_and_waits_for_verification(): void
    {
        $r = $this->request()->assertCreated();
        $this->assertSame('pending_verification', $r->json('data.status'));
        $this->assertSame('2026-10-01', $r->json('data.due_at'));      // received 1 Sep + 30 days
        $id = $r->json('data.id');

        // nothing leaves, and nothing is erased, before identity is verified
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/export")->assertStatus(409);
        $e = $this->request('erasure')->json('data.id');
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$e}/erase", ['confirm' => true])->assertStatus(409);
        $this->assertSame('Acme Ltd', Client::find($this->acme->id)->name);

        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/verify", ['method' => 'id_document', 'note' => ''])->assertStatus(422);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/verify", ['method' => 'psychic', 'note' => 'x'])->assertStatus(422);
        $this->verify($id)->assertOk()->assertJsonPath('data.status', 'verified');
        $this->verify($id)->assertStatus(409);
    }

    public function test_the_access_export_has_the_subjects_data_and_withholds_privileged_material(): void
    {
        $case = $this->matter(null, 'open', null);
        $this->doc($case, 'contract.txt');
        DB::table('hearings')->insert(['case_id' => $case->id, 'court_id' => $case->court_id, 'hearing_date' => '2026-11-01', 'hearing_type' => 1, 'notes' => 'SECRET NOTE', 'outcome' => 'SECRET OUTCOME', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->request()->json('data.id');
        $this->verify($id);

        $res = $this->actingAs($this->officer)->post("/api/v1/compliance/dsar/{$id}/export")->assertOk();
        $body = $res->getContent();
        $json = json_decode($body, true);
        $this->assertSame('Acme Ltd', $json['subject']['name']);
        $this->assertSame('0700111222', $json['subject']['phone_number']);
        $this->assertCount(1, $json['matters']);
        $this->assertSame('contract.txt', $json['document_index'][0]['title']);
        $this->assertNotEmpty($json['withheld_pending_review']);
        foreach (['privileged advice summary', 'SECRET NOTE', 'SECRET OUTCOME', 'content contract.txt'] as $leak) {
            $this->assertStringNotContainsString($leak, $body);
        }
        $this->assertSame('completed', DB::table('dsar_requests')->find($id)->status);
        $this->assertSame(1, DB::table('audits')->where('event', 'dsar_access_exported')->count());
    }

    public function test_erasure_anonymises_the_client_and_scrubs_the_audit_trail_but_keeps_financial_records(): void
    {
        $case = $this->matter(null, 'open', null);
        $inv = DB::table('invoices')->insertGetId(['case_id' => $case->id, 'client_id' => $this->acme->id, 'invoice_number' => 'INV-1', 'invoice_date' => now(), 'invoice_due_date' => now(), 'workflow_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->lawyer);
        Client::find($this->acme->id)->update(['name' => 'Acme Limited']);          // creates an audit row holding the name
        $this->assertGreaterThan(0, DB::table('audits')->where('auditable_type', Client::class)->where('auditable_id', $this->acme->id)->where('new_values', 'like', '%Acme Limited%')->count());

        $id = $this->request('erasure')->json('data.id');
        $this->verify($id);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/erase", [])->assertStatus(422);   // confirm is mandatory
        $res = $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/erase", ['confirm' => true])->assertOk();

        $c = DB::table('clients')->find($this->acme->id);
        $this->assertSame('Erased client #'.$this->acme->id, $c->name);
        $this->assertSame('[erased]', $c->phone_number);
        $this->assertSame('[erased]', $c->address);
        $this->assertSame(0, DB::table('audits')->where('auditable_type', Client::class)->where('auditable_id', $this->acme->id)->where(fn ($q) => $q->where('old_values', 'like', '%Acme%')->orWhere('new_values', 'like', '%Acme%')->orWhere('new_values', 'like', '%0700111222%')->orWhere('old_values', 'like', '%1 Moi%'))->count());
        $this->assertSame(1, DB::table('invoices')->where('id', $inv)->count());     // financial records stay
        $out = json_decode(DB::table('dsar_requests')->find($id)->outcome, true);
        $this->assertSame(1, $out['retained']['invoices_and_payments']);
        $this->assertSame(1, $out['retained']['matters']);
        $this->assertSame('completed', $res->json('data.status'));
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/erase", ['confirm' => true])->assertStatus(409);   // closed
    }

    public function test_a_legal_hold_overrides_erasure(): void
    {
        $case = $this->matter();
        $this->hold('case', $case->id)->assertCreated();      // a hold on one matter is enough
        $id = $this->request('erasure')->json('data.id');
        $this->verify($id);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/erase", ['confirm' => true])->assertStatus(409)->assertJsonPath('data.status', 'blocked');

        $this->assertSame('Acme Ltd', DB::table('clients')->find($this->acme->id)->name);
        $this->assertSame('0700111222', DB::table('clients')->find($this->acme->id)->phone_number);
        $this->assertSame(1, DB::table('audits')->where('event', 'dsar_erasure_blocked')->count());

        // once the hold is released the same request can proceed
        $hold = DB::table('legal_holds')->first();
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/holds/{$hold->id}/release", ['reason' => 'matter ended'])->assertOk();
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/erase", ['confirm' => true])->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame('[erased]', DB::table('clients')->find($this->acme->id)->phone_number);
    }

    public function test_requests_about_a_walled_client_are_invisible_to_the_handler(): void
    {
        $id = $this->request()->json('data.id');
        EthicalScreen::create(['user_id' => $this->officer->id, 'client_id' => $this->acme->id, 'reason' => 'conflict']);
        $this->actingAs($this->officer)->getJson("/api/v1/compliance/dsar/{$id}")->assertStatus(404);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/verify", ['method' => 'in_person', 'note' => 'x'])->assertStatus(404);
        $this->assertSame([], $this->actingAs($this->officer)->getJson('/api/v1/compliance/dsar')->json('data'));
        $this->request()->assertStatus(404);
    }

    public function test_a_request_can_be_rejected_with_a_reason_and_then_nothing_proceeds(): void
    {
        $id = $this->request()->json('data.id');
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/reject", ['reason' => ''])->assertStatus(422);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/reject", ['reason' => 'could not verify identity'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->verify($id)->assertStatus(409);
        $this->actingAs($this->officer)->postJson("/api/v1/compliance/dsar/{$id}/export")->assertStatus(409);
    }

    public function test_the_compliance_tables_travel_in_an_export_bundle(): void
    {
        $classified = array_merge(config('portability.include'), array_keys(config('portability.exclude')));
        foreach (['legal_holds', 'retention_policies', 'retention_disposals', 'dsar_requests'] as $t) {
            $this->assertContains($t, $classified);
        }
        $this->assertTrue(app(LegalHolds::class) instanceof LegalHolds);
    }
}
