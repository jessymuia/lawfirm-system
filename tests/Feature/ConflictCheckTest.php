<?php

namespace Tests\Feature;

use App\Conflicts\Names;
use App\Constants\ModulePermissions as P;
use App\Jobs\BackfillMatterParties;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\MatterParty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-9: conflict-of-interest checks at client and matter intake. */
class ConflictCheckTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function intake(): User
    {
        return $this->userWithPermissions([P::RUN_CONFLICT_CHECKS, P::CREATE_CLIENTS, P::LIST_CLIENTS, P::CREATE_CASES, P::LIST_CASES, P::UPDATE_CASES]);
    }

    private function check(array $names, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/conflicts/check', ['purpose' => 'client', 'names' => $names] + $extra);
    }

    /** A matter for $client, against $adverse (through the API so the dual write runs). */
    private function matter(User $u, Client $client, string $adverse, ?string $number = null): Cases
    {
        $id = $this->actingAs($u)->postJson('/api/v1/cases', [
            'case_number' => $number ?? 'HC-'.uniqid(), 'description' => 'Dispute', 'client_id' => $client->id, 'assigned_to' => $u->id,
            'start_date' => '01/10/2026', 'case_type' => 'civil', 'police_station' => 'Central', 'court_id' => Court::factory()->create()->id,
            'opposing_party' => $adverse,
        ])->assertCreated()->json('data.id');

        return Cases::findOrFail($id);
    }

    // ---- matching ---------------------------------------------------------------------------------------------------

    public function test_names_are_compared_the_way_people_write_them(): void
    {
        $s = fn ($a, $b) => Names::score(Names::normalize($a), Names::normalize($b));
        $this->assertSame(1.0, $s('Wanjiru & Co. Ltd', 'WANJIRU AND COMPANY LIMITED'));
        $this->assertGreaterThan(0.9, $s('John Kamau', 'Kamau John'));
        $this->assertGreaterThan(0.8, $s('John Kamau', 'John K. Kamau'));
        $this->assertGreaterThan(0.8, $s('Mohammed Ali', 'Mohamed Ali'));
        $this->assertSame(1.0, $s('Safaricom', 'Safaricom PLC'));
        $this->assertSame(0.0, $s('Kamau', 'John Kamau'), 'a single common name alone is not a match');
        $this->assertSame(0.0, $s('Peter Otieno', 'Paul Otieno'));
        $this->assertSame('254712345678' === '' ? '' : '712345678', Names::phone('+254 712 345 678'));
        $this->assertSame(Names::phone('0712345678'), Names::phone('+254712345678'));
        $this->assertSame('P051234567X', Names::identifier('p051-234-567x'));
    }

    // ---- what a check finds ------------------------------------------------------------------------------------------

    public function test_taking_on_the_other_side_of_an_existing_matter_is_a_conflict(): void
    {
        $u = $this->intake();
        $client = Client::factory()->create(['name' => 'Acme Holdings Ltd']);
        $this->matter($u, $client, 'Kenya Power and Lighting Company');

        // a new client who is the adverse party of an existing matter
        $r = $this->actingAs($u)->check([['name' => 'Kenya Power & Lighting Co', 'role' => 'client']])->assertOk()
            ->assertJsonPath('data.outcome', 'potential')
            ->assertJsonPath('data.hits.0.kind', 'conflict')
            ->assertJsonPath('data.hits.0.found.role', 'adverse');
        $this->assertNotNull($r->json('data.hits.0.matter.case_number'));

        // a new matter against an existing client
        $this->check([['name' => 'ACME HOLDINGS LIMITED', 'role' => 'adverse']])->assertJsonPath('data.outcome', 'potential')
            ->assertJsonPath('data.hits.0.kind', 'conflict')->assertJsonPath('data.hits.0.found.source', 'client');

        // against someone we are already against: worth showing, not a conflict
        $this->check([['name' => 'Kenya Power and Lighting', 'role' => 'adverse']])->assertJsonPath('data.outcome', 'clear')
            ->assertJsonPath('data.hits.0.kind', 'same_side');

        // nothing in common
        $this->check([['name' => 'Totally Unrelated Bakery', 'role' => 'client']])->assertJsonPath('data.outcome', 'clear')->assertJsonCount(0, 'data.hits');

        // every check is on the record
        $this->assertSame(4, DB::table('conflict_checks')->count());
    }

    public function test_phone_numbers_and_identifiers_match_whatever_the_name(): void
    {
        $u = $this->intake();
        $existing = Client::factory()->create(['name' => 'Grace Wanjiru', 'phone_number' => '0712 345 678']);
        $matter = $this->matter($u, $existing, 'Somebody');
        MatterParty::create(['case_id' => $matter->id, 'role' => 'adverse', 'name' => 'Blue Ocean Traders', 'identifier' => 'PVT-123/45']);

        $this->actingAs($u)->check([['name' => 'G. W. Njeri', 'role' => 'client']], ['phone' => '+254712345678'])
            ->assertJsonPath('data.hits.0.reason', 'phone')->assertJsonPath('data.hits.0.kind', 'existing_client');
        $this->check([['name' => 'Ocean Blue Ltd', 'role' => 'client']], ['identifier' => 'pvt12345'])
            ->assertJsonPath('data.outcome', 'potential')->assertJsonPath('data.hits.0.reason', 'identifier');
    }

    public function test_closed_matters_and_former_clients_still_count(): void
    {
        $u = $this->intake();
        $m = $this->matter($u, Client::factory()->create(['name' => 'Former Client Ltd']), 'Old Adversary Holdings');
        DB::table('cases')->where('id', $m->id)->update(['lifecycle_status' => 'closed']);

        $this->actingAs($u)->check([['name' => 'Old Adversary Holdings', 'role' => 'client']])
            ->assertJsonPath('data.outcome', 'potential')->assertJsonPath('data.hits.0.matter.closed', true);
    }

    public function test_matters_the_checker_cannot_open_are_found_but_not_described(): void
    {
        config(['matters.access' => 'restricted']);
        $partner = $this->userWithPermissions([P::RUN_CONFLICT_CHECKS, P::CREATE_CASES, P::LIST_CASES, P::ACCESS_ALL_MATTERS]);
        $secret = $this->matter($partner, Client::factory()->create(['name' => 'Confidential Client Ltd']), 'Hostile Bidder Holdings', 'SECRET-1');

        $junior = $this->userWithPermissions([P::RUN_CONFLICT_CHECKS, P::LIST_CASES]);
        $r = $this->actingAs($junior)->check([['name' => 'Hostile Bidder Holdings', 'role' => 'client']])->assertOk()
            ->assertJsonPath('data.outcome', 'potential')
            ->assertJsonPath('data.hits.0.kind', 'conflict')
            ->assertJsonPath('data.hits.0.accessible', false)
            ->assertJsonPath('data.hits.0.matter.case_number', null)
            ->assertJsonPath('data.hits.0.matter.id', null)
            ->assertJsonPath('data.hits.0.found.name', null);
        $this->assertStringNotContainsString('SECRET-1', $r->getContent());
        $this->assertStringNotContainsString('Confidential Client', $r->getContent());
        $this->assertNotNull($secret);
    }

    // ---- intake ------------------------------------------------------------------------------------------------------

    public function test_with_the_policy_on_intake_needs_a_cleared_check_for_that_name(): void
    {
        $u = $this->intake();
        $admin = $this->userWithPermissions([P::MANAGE_CONFLICTS]);
        $this->actingAs($admin)->putJson('/api/v1/conflicts/policy', ['required' => true])->assertOk()->assertJsonPath('data.required', true);

        $client = ['name' => 'New Client Ltd', 'phone_number' => '0700111222', 'address' => 'Nairobi', 'advocate' => $u->id];
        $this->actingAs($u)->postJson('/api/v1/clients', $client)->assertUnprocessable()->assertJsonPath('code', 'conflict_check_required');

        $other = $this->check([['name' => 'Someone Else', 'role' => 'client']])->json('data.id');
        $this->postJson('/api/v1/clients', $client + ['conflict_check_id' => $other])->assertUnprocessable()->assertJsonPath('code', 'conflict_check_mismatch');

        $check = $this->check([['name' => 'New Client Limited', 'role' => 'client']])->assertJsonPath('data.outcome', 'clear')->json('data.id');
        $id = $this->postJson('/api/v1/clients', $client + ['conflict_check_id' => $check])->assertCreated()->json('data.id');
        $row = DB::table('conflict_checks')->find($check);
        $this->assertSame(['client', $id], [$row->subject_type, (int) $row->subject_id]);

        // a check opens one record only, and only its author's
        $this->postJson('/api/v1/clients', ['phone_number' => '0700111333'] + $client + ['conflict_check_id' => $check])->assertUnprocessable()->assertJsonPath('code', 'conflict_check_used');
        $fresh = $this->check([['name' => 'Another Client', 'role' => 'client']])->json('data.id');
        $this->actingAs($this->intake())->postJson('/api/v1/clients', ['name' => 'Another Client', 'phone_number' => '0700111444', 'address' => 'x', 'advocate' => $u->id, 'conflict_check_id' => $fresh])
            ->assertUnprocessable()->assertJsonPath('code', 'conflict_check_invalid');

        // and checks go stale
        $old = $this->actingAs($u)->check([['name' => 'Late Client', 'role' => 'client']])->json('data.id');
        DB::table('conflict_checks')->where('id', $old)->update(['created_at' => now()->subHours(25)]);
        $this->postJson('/api/v1/clients', ['name' => 'Late Client', 'phone_number' => '0700111555', 'address' => 'x', 'advocate' => $u->id, 'conflict_check_id' => $old])
            ->assertUnprocessable()->assertJsonPath('code', 'conflict_check_stale');
    }

    public function test_a_possible_conflict_needs_a_recorded_decision_to_proceed(): void
    {
        $u = $this->intake();
        $admin = $this->userWithPermissions([P::MANAGE_CONFLICTS, P::RUN_CONFLICT_CHECKS]);
        $existing = Client::factory()->create(['name' => 'Big Bank Kenya Ltd']);
        $client = Client::factory()->create(['name' => 'Small Borrower']);

        $check = $this->actingAs($u)->postJson('/api/v1/conflicts/check', ['purpose' => 'matter', 'names' => [['name' => 'Big Bank Kenya', 'role' => 'adverse']]])
            ->assertJsonPath('data.outcome', 'potential')->assertJsonPath('data.usable', false)->json('data.id');
        $matter = ['case_number' => 'HC-9', 'description' => 'Loan', 'client_id' => $client->id, 'assigned_to' => $u->id, 'start_date' => '01/10/2026',
            'case_type' => 'civil', 'police_station' => 'x', 'court_id' => Court::factory()->create()->id, 'opposing_party' => 'Big Bank Kenya', 'conflict_check_id' => $check];
        $this->postJson('/api/v1/cases', $matter)->assertUnprocessable()->assertJsonPath('code', 'conflict_unresolved');

        // the person who ran it cannot wave it through
        $this->putJson("/api/v1/conflicts/{$check}/decision", ['decision' => 'proceed', 'note' => 'It is fine, trust me'])->assertForbidden();
        // a conflicts administrator can, with a reason
        $this->actingAs($admin)->putJson("/api/v1/conflicts/{$check}/decision", ['decision' => 'proceed'])->assertUnprocessable();
        $this->putJson("/api/v1/conflicts/{$check}/decision", ['decision' => 'proceed', 'note' => 'Different branch; both clients gave informed consent in writing.'])
            ->assertOk()->assertJsonPath('data.usable', true);
        $this->assertSame(1, DB::table('audits')->where('event', 'conflict_proceed')->count());

        $this->actingAs($u)->postJson('/api/v1/cases', $matter)->assertCreated();
        $this->assertSame('matter', DB::table('conflict_checks')->where('id', $check)->value('subject_type'));
        $this->assertSame(1, MatterParty::where('name', 'Big Bank Kenya')->where('from_opposing_party', true)->count(), 'the opposing party is in the register');

        // a declined intake cannot be used
        $declined = $this->postJson('/api/v1/conflicts/check', ['purpose' => 'matter', 'names' => [['name' => 'Big Bank Kenya', 'role' => 'adverse']]])->json('data.id');
        $this->actingAs($admin)->putJson("/api/v1/conflicts/{$declined}/decision", ['decision' => 'declined', 'note' => 'We act for the bank on its loan book.'])->assertOk();
        $this->actingAs($u)->postJson('/api/v1/cases', ['case_number' => 'HC-10', 'conflict_check_id' => $declined] + $matter)->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'declined'));
    }

    public function test_without_the_policy_intake_works_as_before_and_a_given_check_is_still_validated(): void
    {
        $u = $this->intake();
        $this->actingAs($u)->postJson('/api/v1/clients', ['name' => 'Walk In', 'phone_number' => '0700222333', 'address' => 'x', 'advocate' => $u->id])->assertCreated();
        $this->postJson('/api/v1/clients', ['name' => 'Walk In Two', 'phone_number' => '0700222444', 'address' => 'x', 'advocate' => $u->id, 'conflict_check_id' => 999999])
            ->assertUnprocessable()->assertJsonPath('code', 'conflict_check_invalid');
    }

    // ---- the parties register ------------------------------------------------------------------------------------------

    public function test_parties_are_kept_with_the_matter_and_the_opposing_party_stays_in_step(): void
    {
        $u = $this->intake();
        $m = $this->matter($u, Client::factory()->create(), 'First Opponent');

        $this->actingAs($u)->postJson("/api/v1/cases/{$m->id}/parties", ['role' => 'opposing_counsel', 'name' => 'Otieno & Partners Advocates'])->assertCreated();
        $this->postJson("/api/v1/cases/{$m->id}/parties", ['role' => 'judge', 'name' => 'x'])->assertUnprocessable();
        $parties = $this->getJson("/api/v1/cases/{$m->id}/parties")->assertOk()->json('data');
        $this->assertCount(2, $parties);

        // renaming the opposing party renames its mirrored party; it cannot be deleted from the register
        $mirrored = collect($parties)->firstWhere('from_opposing_party', true);
        $this->deleteJson("/api/v1/cases/{$m->id}/parties/{$mirrored['id']}")->assertUnprocessable();
        $this->putJson("/api/v1/cases/{$m->id}", [
            'case_number' => $m->case_number, 'description' => 'x', 'client_id' => $m->client_id, 'assigned_to' => $u->id, 'start_date' => '01/10/2026',
            'case_type' => 'civil', 'police_station' => 'x', 'court_id' => $m->court_id, 'opposing_party' => 'Renamed Opponent',
        ])->assertOk();
        $this->assertSame('Renamed Opponent', MatterParty::find($mirrored['id'])->name);
        $this->assertSame('renamed opponent', MatterParty::find($mirrored['id'])->name_normalized);

        $other = collect($parties)->firstWhere('from_opposing_party', false);
        $this->deleteJson("/api/v1/cases/{$m->id}/parties/{$other['id']}")->assertOk();
        $this->assertSoftDeleted('matter_parties', ['id' => $other['id']]);
    }

    public function test_existing_opposing_parties_are_backfilled_once(): void
    {
        $client = Client::factory()->create();
        $a = Cases::factory()->create(['client_id' => $client->id, 'opposing_party' => 'Legacy Opponent Ltd']);
        Cases::factory()->create(['client_id' => $client->id, 'opposing_party' => 'Another Legacy Party']);

        $this->assertSame(2, (new BackfillMatterParties)->handle());
        $this->assertSame(0, (new BackfillMatterParties)->handle(), 'idempotent');
        $this->assertSame('legacy opponent', DB::table('matter_parties')->where('case_id', $a->id)->value('name_normalized'));
    }

    // ---- permissions -------------------------------------------------------------------------------------------------

    public function test_permissions(): void
    {
        $nobody = $this->userWithPermissions([P::LIST_CLIENTS]);
        $this->actingAs($nobody)->postJson('/api/v1/conflicts/check', ['purpose' => 'client', 'names' => [['name' => 'x y', 'role' => 'client']]])->assertForbidden();
        $this->getJson('/api/v1/conflicts')->assertForbidden();
        $this->putJson('/api/v1/conflicts/policy', ['required' => true])->assertForbidden();

        $u = $this->intake();
        $mine = $this->actingAs($u)->check([['name' => 'Some Name', 'role' => 'client']])->json('data.id');
        $this->getJson("/api/v1/conflicts/{$mine}")->assertOk();
        $theirs = $this->actingAs($this->intake())->check([['name' => 'Other Name', 'role' => 'client']])->json('data.id');
        $this->actingAs($u)->getJson("/api/v1/conflicts/{$theirs}")->assertNotFound();

        $admin = $this->userWithPermissions([P::MANAGE_CONFLICTS, P::RUN_CONFLICT_CHECKS]);
        $this->actingAs($admin)->getJson("/api/v1/conflicts/{$theirs}")->assertOk();
        $this->getJson('/api/v1/conflicts?page=1')->assertOk()->assertJsonPath('data.total', 2);
    }
}
