<?php

namespace Tests\Feature;

use App\Jobs\SendSmsJob;
use App\Models\Cases;
use App\Models\Client;
use App\Models\EthicalScreen;
use App\Models\EthicalScreenAttempt;
use App\Models\Hearing;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use OwenIt\Auditing\Models\Audit;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-11: ethical walls / conflict screens. */
class EthicalWallTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $alice;     // the person who will be walled off

    private User $officer;   // compliance: manages screens, has no matter access of their own

    private Client $acme;

    private Client $globex;

    private Cases $acme1;

    private Cases $acme2;

    private Cases $globex1;

    protected function setUp(): void
    {
        parent::setUp();
        config(['audit.console' => true]);
        $perms = ['list-cases', 'update-cases', 'list-clients', 'list-hearings', 'create-hearings', 'list-invoices', 'update-hearings'];
        $this->alice = $this->userWithPermissions($perms);
        $this->officer = $this->userWithPermissions(['manage-ethical-screens']);

        $this->acme = Client::factory()->create(['name' => 'Acme Ltd']);
        $this->globex = Client::factory()->create(['name' => 'Globex Ltd']);
        // alice is the assigned lawyer on all of them: the wall must beat assignment
        $mk = fn ($n, $client) => Cases::factory()->create(['case_number' => $n, 'client_id' => $client->id, 'assigned_to' => $this->alice->id, 'description' => 'walrus '.$n]);
        $this->acme1 = $mk('ACME-1', $this->acme);
        $this->acme2 = $mk('ACME-2', $this->acme);
        $this->globex1 = $mk('GLOBEX-1', $this->globex);
    }

    private function wall(array $attrs): EthicalScreen
    {
        return EthicalScreen::create($attrs + ['user_id' => $this->alice->id, 'reason' => 'conflict check']);
    }

    private function numbers(User $u): array
    {
        $r = $this->actingAs($u)->getJson('/api/v1/cases?per_page=100')->assertOk();

        return collect($r->json('data.data') ?? $r->json('data') ?? [])->pluck('case_number')->sort()->values()->all();
    }

    public function test_a_matter_wall_hides_that_matter_from_that_user_only_even_in_firm_mode(): void
    {
        $this->assertSame('firm', config('matters.access'));
        $this->wall(['case_id' => $this->acme1->id]);

        $this->assertSame(['ACME-2', 'GLOBEX-1'], $this->numbers($this->alice));
        $other = $this->userWithPermissions(['list-cases']);
        $this->assertSame(['ACME-1', 'ACME-2', 'GLOBEX-1'], $this->numbers($other), 'nobody else is affected');
    }

    public function test_the_wall_overrides_assignment_creator_grant_and_the_all_matters_bypass(): void
    {
        config(['matters.access' => 'restricted']);
        $partner = $this->userWithPermissions(['list-cases', 'access-all-matters']);
        $this->assertSame(['ACME-1', 'ACME-2', 'GLOBEX-1'], $this->numbers($partner));

        $this->wall(['case_id' => $this->acme1->id, 'user_id' => $partner->id]);
        DB::table('matter_access_grants')->insert(['case_id' => $this->acme1->id, 'user_id' => $partner->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['ACME-2', 'GLOBEX-1'], $this->numbers($partner), 'bypass + grant do not beat a wall');
        $this->actingAs($partner)->getJson("/api/v1/cases/{$this->acme1->id}")->assertNotFound();
    }

    public function test_a_client_wall_hides_the_client_and_every_matter_of_that_client_including_future_ones(): void
    {
        $inv = DB::table('invoices')->insertGetId(['case_id' => $this->acme1->id, 'client_id' => $this->acme->id, 'invoice_number' => 'INV-A', 'invoice_date' => now(), 'invoice_due_date' => now(), 'workflow_status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $h = Hearing::factory()->create(['case_id' => $this->acme2->id]);
        $this->wall(['client_id' => $this->acme->id]);

        $this->assertSame(['GLOBEX-1'], $this->numbers($this->alice));
        $resp = $this->actingAs($this->alice)->getJson('/api/v1/clients?per_page=100')->assertOk();
        $clients = collect($resp->json('data.data'))->pluck('name')->all();
        $this->assertNotContains('Acme Ltd', $clients);
        $this->assertContains('Globex Ltd', $clients);
        $this->getJson("/api/v1/clients/{$this->acme->id}")->assertNotFound();

        $this->assertSame(0, Invoice::count(), 'invoices of walled matters are hidden');
        $this->assertNull(Hearing::find($h->id));

        // a matter opened for the walled client AFTER the wall is covered too
        $later = Cases::factory()->create(['case_number' => 'ACME-3', 'client_id' => $this->acme->id, 'assigned_to' => $this->alice->id]);
        $this->assertSame(['GLOBEX-1'], $this->numbers($this->alice));
        $this->assertNull(Cases::find($later->id));
    }

    public function test_search_and_export_do_not_surface_walled_matters(): void
    {
        $this->wall(['client_id' => $this->acme->id]);
        $this->actingAs($this->alice);
        $this->userWithPermissions([]); // no-op: keep ids moving

        $r = $this->getJson('/api/v1/search?q=walrus')->assertOk();
        $titles = collect($r->json('data.cases') ?? $r->json('cases') ?? [])->pluck('title')->all();
        $this->assertSame(['GLOBEX-1'], $titles);

        $csv = $this->get('/api/v1/export/cases')->assertOk()->streamedContent();
        $this->assertStringContainsString('GLOBEX-1', $csv);
        $this->assertStringNotContainsString('ACME', $csv);
    }

    public function test_writing_against_a_walled_matter_is_refused_and_logged(): void
    {
        $screen = $this->wall(['case_id' => $this->acme1->id]);
        $this->actingAs($this->alice);

        try {
            Hearing::factory()->create(['case_id' => $this->acme1->id]);
            $this->fail('expected AuthorizationException');
        } catch (AuthorizationException) {
            $a = EthicalScreenAttempt::where('surface', 'write')->first();
            $this->assertNotNull($a);
            $this->assertSame($screen->id, $a->screen_id);
            $this->assertSame($this->alice->id, $a->user_id);
        }
    }

    public function test_attempts_to_reach_walled_material_are_logged_whatever_the_outcome(): void
    {
        $screen = $this->wall(['case_id' => $this->acme1->id]);
        $hearing = Hearing::factory()->create(['case_id' => $this->acme1->id]);
        $this->actingAs($this->alice);

        $this->getJson("/api/v1/cases/{$this->acme1->id}")->assertNotFound();
        $this->assertSame(1, EthicalScreenAttempt::count());
        $a = EthicalScreenAttempt::first();
        $this->assertSame([$screen->id, $this->alice->id, $this->acme1->id, 'api', 'GET', 404], [$a->screen_id, $a->user_id, $a->case_id, $a->surface, $a->method, $a->status]);
        $this->assertSame("api/v1/cases/{$this->acme1->id}", $a->path);

        $this->getJson('/api/v1/hearings?case_id='.$this->acme1->id)->assertOk();   // a "successful" but empty list is still an attempt
        $this->assertSame(2, EthicalScreenAttempt::count());

        $this->putJson("/api/v1/hearings/{$hearing->id}", ['notes' => 'x']);          // child record reached by its own id
        $this->assertSame(3, EthicalScreenAttempt::count());
    }

    public function test_ordinary_traffic_is_not_logged(): void
    {
        $this->wall(['case_id' => $this->acme1->id]);
        $this->actingAs($this->alice);
        $this->getJson("/api/v1/cases/{$this->acme2->id}")->assertOk();
        $this->getJson('/api/v1/cases')->assertOk();
        $this->getJson('/api/v1/hearings')->assertOk();

        $bystander = $this->userWithPermissions(['list-cases']);
        $this->actingAs($bystander)->getJson("/api/v1/cases/{$this->acme1->id}")->assertOk();

        $this->assertSame(0, EthicalScreenAttempt::count());
    }

    public function test_hearing_reminders_skip_the_screened_lawyer_but_still_reach_the_client_and_are_logged(): void
    {
        Bus::fake();
        $this->alice->forceFill(['phone_number' => '254700000001'])->save();
        $this->acme->forceFill(['phone_number' => '254700000002'])->save();
        $this->globex->forceFill(['phone_number' => '254700000003'])->save();
        Hearing::factory()->create(['case_id' => $this->acme1->id, 'hearing_date' => now()->addDay(), 'reminder_sent_at' => null]);
        $this->wall(['case_id' => $this->acme1->id]);

        $this->artisan('hearings:send-reminders')->assertExitCode(0);

        Bus::assertDispatched(SendSmsJob::class, fn ($j) => $this->jobPhone($j) === '254700000002');
        Bus::assertNotDispatched(SendSmsJob::class, fn ($j) => $this->jobPhone($j) === '254700000001');
        $this->assertSame('notification', EthicalScreenAttempt::first()?->surface);
    }

    private function jobPhone($job): ?string
    {
        $r = new \ReflectionObject($job);
        foreach ($r->getProperties() as $p) {
            $p->setAccessible(true);
            $v = $p->getValue($job);
            if (is_string($v) && preg_match('/^2547\d+$/', $v)) {
                return $v;
            }
        }

        return null;
    }

    public function test_lifting_restores_access_keeps_the_record_and_stops_logging(): void
    {
        $screen = $this->wall(['case_id' => $this->acme1->id]);
        $this->actingAs($this->officer)->postJson("/api/v1/ethical-screens/{$screen->id}/lift", ['reason' => 'conflict cleared'])->assertOk();

        $this->assertSame(['ACME-1', 'ACME-2', 'GLOBEX-1'], $this->numbers($this->alice));
        $this->assertSame(0, EthicalScreenAttempt::count(), 'no attempt once lifted');

        $row = EthicalScreen::findOrFail($screen->id);
        $this->assertNotNull($row->lifted_at);
        $this->assertSame($this->officer->id, $row->lifted_by);
        $this->assertSame('conflict cleared', $row->lift_reason);
        $this->actingAs($this->officer)->postJson("/api/v1/ethical-screens/{$screen->id}/lift", ['reason' => 'again again'])->assertNotFound();
    }

    public function test_creating_and_lifting_are_audited(): void
    {
        $r = $this->actingAs($this->officer)->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'case_id' => $this->acme1->id, 'reason' => 'former client of lawyer'])->assertCreated();
        $id = $r->json('data.id');
        $this->actingAs($this->officer)->postJson("/api/v1/ethical-screens/{$id}/lift", ['reason' => 'waiver signed'])->assertOk();

        $audits = Audit::where('auditable_type', EthicalScreen::class)->where('auditable_id', $id)->get();
        $this->assertEqualsCanonicalizing(['created', 'updated'], $audits->pluck('event')->all());
        $this->assertSame($this->officer->id, $audits->firstWhere('event', 'created')->user_id);
    }

    public function test_management_rules_and_validation(): void
    {
        // permission
        $this->actingAs($this->alice)->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'case_id' => $this->acme1->id, 'reason' => 'whatever x'])->assertForbidden();
        $this->actingAs($this->alice)->getJson('/api/v1/ethical-screens')->assertForbidden();
        $this->actingAs($this->alice)->getJson('/api/v1/ethical-screens/attempts')->assertForbidden();

        $this->actingAs($this->officer);
        // exactly one of case / client, and a real reason
        $this->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'reason' => 'no target'])->assertStatus(422);
        $this->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'case_id' => $this->acme1->id, 'client_id' => $this->acme->id, 'reason' => 'both targets'])->assertStatus(422);
        $this->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'case_id' => $this->acme1->id, 'reason' => 'x'])->assertStatus(422);
        // idempotent
        $this->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'case_id' => $this->acme1->id, 'reason' => 'first time'])->assertCreated();
        $this->postJson('/api/v1/ethical-screens', ['user_id' => $this->alice->id, 'case_id' => $this->acme1->id, 'reason' => 'second time'])->assertOk();
        $this->assertSame(1, EthicalScreen::count());
        // nobody screens or unscreens themselves
        $this->postJson('/api/v1/ethical-screens', ['user_id' => $this->officer->id, 'case_id' => $this->acme1->id, 'reason' => 'self screen'])->assertForbidden();
        $own = $this->wall(['user_id' => $this->officer->id, 'case_id' => $this->globex1->id]);
        $this->postJson("/api/v1/ethical-screens/{$own->id}/lift", ['reason' => 'lift my own'])->assertForbidden();
        $this->assertNull($own->fresh()->lifted_at);
    }

    public function test_screens_and_attempts_can_never_be_deleted_or_rewritten(): void
    {
        $screen = $this->wall(['case_id' => $this->acme1->id]);
        try {
            $screen->delete();
            $this->fail('screen deleted');
        } catch (\LogicException) {
            $this->assertNotNull(EthicalScreen::find($screen->id));
        }

        $attempt = EthicalScreenAttempt::create(['user_id' => $this->alice->id, 'surface' => 'api']);
        foreach ([fn () => $attempt->update(['surface' => 'x']), fn () => $attempt->delete()] as $mutation) {
            try {
                $mutation();
                $this->fail('attempt mutated');
            } catch (\LogicException) {
            }
        }
        $this->assertSame('api', $attempt->fresh()->surface);
    }

    public function test_reports_show_screens_and_attempts_without_exposing_matter_content_and_export_csv(): void
    {
        $s1 = $this->wall(['case_id' => $this->acme1->id]);
        $s2 = $this->wall(['client_id' => $this->globex->id]);
        EthicalScreen::findOrFail($s2->id)->update(['lifted_at' => now(), 'lifted_by' => $this->officer->id, 'lift_reason' => 'done']);
        $this->actingAs($this->alice)->getJson("/api/v1/cases/{$this->acme1->id}")->assertNotFound();

        $this->actingAs($this->officer);
        // the officer has no matter access at all, yet the report names the matter
        $active = $this->getJson('/api/v1/ethical-screens')->assertOk()->json('data');
        $this->assertSame([$s1->id], collect($active)->pluck('id')->all());
        $this->assertSame('ACME-1', $active[0]['case_number']);
        $this->assertSame($this->alice->name, $active[0]['user_name']);

        $lifted = $this->getJson('/api/v1/ethical-screens?status=lifted')->assertOk()->json('data');
        $this->assertSame([$s2->id], collect($lifted)->pluck('id')->all());
        $this->assertCount(2, $this->getJson('/api/v1/ethical-screens?status=all')->json('data'));
        $this->assertCount(1, $this->getJson('/api/v1/ethical-screens?user_id='.$this->alice->id.'&case_id='.$this->acme1->id)->json('data'));

        $attempts = $this->getJson('/api/v1/ethical-screens/attempts')->assertOk()->json('data');
        $this->assertCount(1, $attempts);
        $this->assertSame('ACME-1', $attempts[0]['case_number']);
        $this->assertSame(404, $attempts[0]['status']);
        $this->assertCount(0, $this->getJson('/api/v1/ethical-screens/attempts?from='.now()->addDay()->toDateString())->json('data'));

        $csv = $this->get('/api/v1/ethical-screens?format=csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('Screened user', $csv);
        $this->assertStringContainsString('ACME-1', $csv);
        $this->assertStringContainsString('ACME-1', $this->get('/api/v1/ethical-screens/attempts?format=csv')->streamedContent());
    }

    public function test_a_screened_user_without_access_rules_is_unaffected_elsewhere_and_unauthenticated_contexts_are_never_walled(): void
    {
        $this->wall(['client_id' => $this->acme->id]);
        auth()->logout();

        $this->assertSame(3, Cases::count(), 'console / queue contexts see everything');
        $this->assertSame(2, Client::count());
    }
}
