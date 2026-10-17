<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\Office;
use App\Models\PracticeGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-18: offices and practice groups inside one firm. */
class OrganisationTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function admin(): User
    {
        return $this->userWithPermissions([P::MANAGE_ORGANISATION, P::CREATE_CASES, P::LIST_CASES, P::UPDATE_CASES, P::CREATE_USERS, P::UPDATE_USERS, P::LIST_USERS]);
    }

    private function matterPayload(User $lawyer, array $extra = []): array
    {
        return $extra + [
            'case_number' => 'HC-'.uniqid(), 'description' => 'x', 'client_id' => Client::factory()->create()->id, 'assigned_to' => $lawyer->id,
            'start_date' => '01/10/2026', 'case_type' => 'civil', 'police_station' => 'x', 'court_id' => Court::factory()->create()->id, 'opposing_party' => 'Other Side',
        ];
    }

    public function test_offices_and_groups_are_managed_and_there_is_one_head_office(): void
    {
        $a = $this->admin();
        $nbo = $this->actingAs($a)->postJson('/api/v1/organisation/offices', ['name' => 'Nairobi', 'code' => 'NBO', 'city' => 'Nairobi', 'is_head_office' => true])->assertCreated()->json('data.id');
        $msa = $this->postJson('/api/v1/organisation/offices', ['name' => 'Mombasa', 'code' => 'MSA', 'is_head_office' => true])->assertCreated()->json('data.id');
        $this->assertFalse(Office::find($nbo)->is_head_office, 'a new head office replaces the old one');
        $this->assertTrue(Office::find($msa)->is_head_office);
        $this->postJson('/api/v1/organisation/offices', ['name' => 'Nairobi'])->assertUnprocessable()->assertJsonValidationErrors('name');

        $lit = $this->postJson('/api/v1/organisation/practice-groups', ['name' => 'Litigation', 'head_user_id' => $a->id])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/organisation/practice-groups/{$lit}", ['name' => 'Litigation & Disputes', 'head_user_id' => $a->id])->assertOk();
        $this->putJson("/api/v1/organisation/offices/{$nbo}", ['name' => 'Nairobi HQ', 'is_head_office' => true])->assertOk();
        $this->assertTrue(Office::find($nbo)->is_head_office);
        $this->assertFalse(Office::find($msa)->is_head_office);

        $this->getJson('/api/v1/organisation')->assertOk()
            ->assertJsonPath('data.offices.0.name', 'Nairobi HQ')
            ->assertJsonPath('data.practice_groups.0.head', $a->name);
        $this->assertSame('Litigation & Disputes', PracticeGroup::find($lit)->name);
    }

    public function test_a_matter_belongs_where_its_lawyer_works_unless_told_otherwise_and_lists_filter_by_it(): void
    {
        $a = $this->admin();
        $nbo = Office::create(['name' => 'Nairobi']);
        $msa = Office::create(['name' => 'Mombasa']);
        $lit = PracticeGroup::create(['name' => 'Litigation']);
        $lawyer = $this->userWithPermissions([P::LIST_CASES]);
        $lawyer->forceFill(['office_id' => $nbo->id, 'practice_group_id' => $lit->id])->save();

        $m1 = $this->actingAs($a)->postJson('/api/v1/cases', $this->matterPayload($lawyer))->assertCreated()
            ->assertJsonPath('data.office', 'Nairobi')->assertJsonPath('data.practice_group', 'Litigation')->json('data.id');
        $m2 = $this->postJson('/api/v1/cases', $this->matterPayload($lawyer, ['office_id' => $msa->id, 'practice_group_id' => null]))->assertCreated()
            ->assertJsonPath('data.office_id', $msa->id)->assertJsonPath('data.practice_group_id', null)->json('data.id');

        $this->getJson("/api/v1/cases?page=1&office_id={$nbo->id}")->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.id', $m1);
        $this->getJson("/api/v1/cases?page=1&office_id={$msa->id}")->assertJsonPath('data.data.0.id', $m2);
        $this->getJson("/api/v1/cases?page=1&practice_group_id={$lit->id}")->assertJsonPath('data.total', 1);

        // an update that does not mention them leaves them alone
        $m = Cases::find($m1);
        $this->putJson("/api/v1/cases/{$m1}", $this->matterPayload($lawyer, ['case_number' => $m->case_number, 'client_id' => $m->client_id, 'court_id' => $m->court_id]))->assertOk();
        $this->assertSame($nbo->id, Cases::find($m1)->office_id);
        $this->putJson("/api/v1/cases/{$m1}", $this->matterPayload($lawyer, ['case_number' => $m->case_number, 'client_id' => $m->client_id, 'court_id' => $m->court_id, 'office_id' => $msa->id]))->assertOk();
        $this->assertSame($msa->id, Cases::find($m1)->office_id);
        $this->postJson('/api/v1/cases', $this->matterPayload($lawyer, ['office_id' => 999999]))->assertUnprocessable()->assertJsonValidationErrors('office_id');
    }

    public function test_people_get_an_office_and_group_and_can_be_moved_with_their_open_matters(): void
    {
        $a = $this->admin();
        $nbo = Office::create(['name' => 'Nairobi']);
        $msa = Office::create(['name' => 'Mombasa']);
        $id = $this->actingAs($a)->postJson('/api/v1/users', ['name' => 'Jane Advocate', 'phone_number' => '0711', 'email' => 'jane@firm.test', 'department' => 'Legal',
            'hire_date' => '01/01/2026', 'office_id' => $nbo->id])->assertCreated()->assertJsonPath('data.office_id', $nbo->id)->json('data.id');
        $this->getJson("/api/v1/users?page=1&office_id={$nbo->id}")->assertOk()->assertJsonPath('data.total', 1);
        $jane = User::find($id);

        $open = $this->postJson('/api/v1/cases', $this->matterPayload($jane))->assertCreated()->json('data.id');
        $closed = $this->postJson('/api/v1/cases', $this->matterPayload($jane))->assertCreated()->json('data.id');
        Cases::whereKey($closed)->update(['lifecycle_status' => 'closed']);

        $this->postJson('/api/v1/organisation/assign', ['user_ids' => [$id], 'office_id' => $msa->id, 'apply_to_open_matters' => true])->assertOk()
            ->assertJsonPath('data.people', 1)->assertJsonPath('data.matters', 1);
        $this->assertSame($msa->id, $jane->fresh()->office_id);
        $this->assertSame($msa->id, Cases::find($open)->office_id);
        $this->assertSame($nbo->id, Cases::find($closed)->office_id, 'closed matters keep their history');
        $this->postJson('/api/v1/organisation/assign', ['user_ids' => [$id]])->assertUnprocessable();
    }

    public function test_an_office_still_in_use_cannot_be_removed(): void
    {
        $a = $this->admin();
        $nbo = Office::create(['name' => 'Nairobi']);
        $a->forceFill(['office_id' => $nbo->id])->save();
        $this->actingAs($a)->deleteJson("/api/v1/organisation/offices/{$nbo->id}")->assertUnprocessable()->assertJsonPath('data.people', 1);

        $a->forceFill(['office_id' => null])->save();
        $this->deleteJson("/api/v1/organisation/offices/{$nbo->id}")->assertOk();
        $this->assertSoftDeleted('offices', ['id' => $nbo->id]);
        $this->getJson('/api/v1/organisation/options')->assertOk()->assertJsonCount(0, 'data.offices');
    }

    public function test_everyone_reads_the_names_only_administrators_change_them(): void
    {
        Office::create(['name' => 'Nairobi']);
        $u = $this->userWithPermissions([P::LIST_CLIENTS]);
        $this->actingAs($u)->getJson('/api/v1/organisation/options')->assertOk()->assertJsonPath('data.offices.0.name', 'Nairobi');
        $this->getJson('/api/v1/organisation')->assertForbidden();
        $this->postJson('/api/v1/organisation/offices', ['name' => 'X'])->assertForbidden();
        $this->postJson('/api/v1/organisation/assign', ['user_ids' => [$u->id], 'office_id' => null])->assertForbidden();
    }
}
