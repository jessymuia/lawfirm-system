<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Jobs\SendSmsJob;
use App\Models\Client;
use App\Models\TrustTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-12: trust three-way reconciliation and retainer replenishment. */
class TrustReconciliationTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $u;

    protected function setUp(): void
    {
        parent::setUp();
        $this->usePlan('professional');   // trust accounting is a plan feature
        $this->u = $this->userWithPermissions([P::RECONCILE_TRUST_ACCOUNTS, P::LIST_TRUST_TRANSACTIONS, P::CREATE_TRUST_TRANSACTIONS]);
    }

    /** Post a ledger entry on a given day (the ledger is append-only, so the clock is moved rather than the row). */
    private function entry(string $day, Client $c, string $type, float $amount): TrustTransaction
    {
        $this->travelTo(Carbon::parse($day.' 10:00:00'));
        $t = TrustTransaction::post($c->id, null, $type, $amount, ucfirst($type), 'REF-'.uniqid(), $this->u->id);
        $this->travelBack();

        return $t;
    }

    /** May: A deposits 1000, B deposits 500, A disburses 200 (cheque not yet presented), B deposits 300 on the 31st (not yet on the statement). */
    private function may(): array
    {
        $a = Client::factory()->create(['name' => 'Alpha Ltd']);
        $b = Client::factory()->create(['name' => 'Beta Holdings']);
        $t = [
            'a1' => $this->entry('2026-05-03', $a, 'deposit', 1000),
            'b1' => $this->entry('2026-05-05', $b, 'deposit', 500),
            'a2' => $this->entry('2026-05-20', $a, 'disbursement', 200),
            'b2' => $this->entry('2026-05-31', $b, 'deposit', 300),
        ];

        return [$a, $b, $t];
    }

    public function test_the_three_balances_agree_once_the_statement_is_matched(): void
    {
        [$a, $b, $t] = $this->may();
        // the bank shows the two early deposits only: 1500
        $id = $this->actingAs($this->u)->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-31', 'bank_balance' => 1500, 'statement_reference' => 'May stmt'])
            ->assertCreated()->json('data.reconciliation.id');

        // before ticking anything every entry is outstanding: bank 1500 + 1800 in transit - 200 = 3100 ≠ ledger 1600
        $this->getJson("/api/v1/trust/reconciliations/{$id}")->assertOk()
            ->assertJsonPath('data.figures.ledger', 1600)
            ->assertJsonPath('data.figures.clients', 1600)
            ->assertJsonPath('data.figures.balanced', false);

        $this->putJson("/api/v1/trust/reconciliations/{$id}/cleared", ['transaction_ids' => [$t['a1']->id, $t['b1']->id], 'cleared' => true])->assertOk()
            ->assertJsonPath('data.figures.deposits_in_transit', 300)
            ->assertJsonPath('data.figures.outstanding_disbursements', 200)
            ->assertJsonPath('data.figures.bank', 1600)
            ->assertJsonPath('data.figures.balanced', true)
            ->assertJsonCount(2, 'data.figures.uncleared');

        $r = $this->postJson("/api/v1/trust/reconciliations/{$id}/sign-off")->assertOk()
            ->assertJsonPath('data.reconciliation.state', 'signed_off')
            ->assertJsonPath('data.reconciliation.has_exceptions', fn ($v) => ! $v);
        $clients = collect($r->json('data.clients'))->keyBy('name');
        $this->assertEquals(800, $clients['Alpha Ltd']['balance']);
        $this->assertEquals(800, $clients['Beta Holdings']['balance']);
        $this->assertSame(1, DB::table('audits')->where('event', 'trust_reconciliation_signed_off')->count());

        // signed off means frozen
        $this->putJson("/api/v1/trust/reconciliations/{$id}/cleared", ['transaction_ids' => [$t['b2']->id], 'cleared' => true])->assertStatus(409);
        $this->deleteJson("/api/v1/trust/reconciliations/{$id}")->assertStatus(409);

        $csv = $this->get("/api/v1/trust/reconciliations/{$id}/export")->assertOk()->streamedContent();
        $this->assertStringContainsString('Adjusted bank balance', $csv);
        $this->assertStringContainsString('Alpha Ltd', $csv);
    }

    public function test_a_later_reconciliation_does_not_change_a_signed_off_one(): void
    {
        [$a, $b, $t] = $this->may();
        $may = $this->actingAs($this->u)->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-31', 'bank_balance' => 1500])->json('data.reconciliation.id');
        $this->putJson("/api/v1/trust/reconciliations/{$may}/cleared", ['transaction_ids' => [$t['a1']->id, $t['b1']->id], 'cleared' => true]);
        $this->postJson("/api/v1/trust/reconciliations/{$may}/sign-off")->assertOk();

        // June: the May items clear, the bank also charged 15 that is not in the ledger yet
        $june = $this->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-06-30', 'bank_balance' => 1585])->assertCreated()->json('data.reconciliation.id');
        $this->putJson("/api/v1/trust/reconciliations/{$june}/cleared", ['transaction_ids' => [$t['a2']->id, $t['b2']->id], 'cleared' => true])->assertOk()
            ->assertJsonPath('data.figures.balanced', false);
        $this->postJson("/api/v1/trust/reconciliations/{$june}/items", ['amount' => 15, 'description' => 'Bank charges not yet posted'])->assertCreated()
            ->assertJsonPath('data.figures.adjustments', 15)->assertJsonPath('data.figures.balanced', true);

        // May still shows what was signed
        $this->getJson("/api/v1/trust/reconciliations/{$may}")->assertJsonPath('data.figures.deposits_in_transit', 300)
            ->assertJsonCount(2, 'data.figures.uncleared');
    }

    public function test_reconciliations_go_forward_one_draft_at_a_time(): void
    {
        $this->may();
        $id = $this->actingAs($this->u)->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-31', 'bank_balance' => 1600])->json('data.reconciliation.id');
        $this->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-06-30', 'bank_balance' => 0])->assertUnprocessable();
        $this->postJson("/api/v1/trust/reconciliations/{$id}/sign-off", ['exception_note' => 'Three deposits not yet traced; see memo 14.'])->assertOk()
            ->assertJsonPath('data.reconciliation.has_exceptions', true);
        $this->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-15', 'bank_balance' => 0])->assertUnprocessable();
        $this->postJson('/api/v1/trust/reconciliations', ['period_end' => '2999-01-01', 'bank_balance' => 0])->assertUnprocessable();
    }

    public function test_an_unbalanced_reconciliation_needs_an_explanation_to_sign_off(): void
    {
        $this->may();
        $id = $this->actingAs($this->u)->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-31', 'bank_balance' => 99])->json('data.reconciliation.id');
        $this->postJson("/api/v1/trust/reconciliations/{$id}/sign-off")->assertUnprocessable();
        $this->assertSame('draft', DB::table('trust_reconciliations')->value('state'));

        // and a draft can be thrown away, with its tick marks
        $this->deleteJson("/api/v1/trust/reconciliations/{$id}")->assertOk();
        $this->assertSame(0, DB::table('trust_reconciliations')->count());
    }

    public function test_only_entries_outstanding_at_the_date_can_be_cleared_and_voids_count_until_voided(): void
    {
        [$a, $b, $t] = $this->may();
        $june = $this->entry('2026-06-10', $a, 'deposit', 50);
        $id = $this->actingAs($this->u)->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-31', 'bank_balance' => 1600])->json('data.reconciliation.id');
        $this->putJson("/api/v1/trust/reconciliations/{$id}/cleared", ['transaction_ids' => [$june->id], 'cleared' => true])->assertUnprocessable();

        // an entry voided in June still counts at the end of May
        $this->travelTo(Carbon::parse('2026-06-15'));
        DB::table('trust_transactions')->where('id', $t['b2']->id)->update(['voided_at' => now(), 'voided_by' => $this->u->id]);
        $this->travelBack();
        $this->getJson("/api/v1/trust/reconciliations/{$id}")->assertJsonPath('data.figures.ledger', 1600);
    }

    public function test_retainers_below_their_minimum_are_listed_and_a_replenishment_request_is_sent_and_logged(): void
    {
        Bus::fake([SendSmsJob::class]);
        [$a, $b] = $this->may();   // Alpha 800, Beta 800
        $this->actingAs($this->u)->putJson('/api/v1/trust/retainers', ['client_id' => $a->id, 'minimum_balance' => 1000, 'target_balance' => 2500])->assertOk();
        $this->putJson('/api/v1/trust/retainers', ['client_id' => $b->id, 'minimum_balance' => 500, 'target_balance' => 1000])->assertOk();
        $this->putJson('/api/v1/trust/retainers', ['client_id' => $b->id, 'minimum_balance' => 500, 'target_balance' => 100])->assertUnprocessable();

        $this->getJson('/api/v1/trust/replenishment')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.client', 'Alpha Ltd')->assertJsonPath('data.0.amount_needed', 1700);

        $this->postJson("/api/v1/trust/replenishment/{$a->id}/request")->assertOk()->assertJsonPath('data.amount_requested', 1700);
        Bus::assertDispatched(SendSmsJob::class);
        $this->assertSame(1, DB::table('trust_replenishment_requests')->where('client_id', $a->id)->count());
        $this->getJson('/api/v1/trust/replenishment')->assertJsonPath('data.0.last_requested_at', fn ($v) => $v !== null);
        $this->postJson("/api/v1/trust/replenishment/{$b->id}/request")->assertUnprocessable();
    }

    public function test_permissions_and_plan(): void
    {
        $viewer = $this->userWithPermissions([P::LIST_TRUST_TRANSACTIONS]);
        $this->actingAs($viewer)->getJson('/api/v1/trust/replenishment')->assertOk();
        $this->getJson('/api/v1/trust/reconciliations')->assertForbidden();
        $this->postJson('/api/v1/trust/reconciliations', ['period_end' => '2026-05-31', 'bank_balance' => 0])->assertForbidden();

        $this->usePlan('starter');   // no trust accounting
        $this->actingAs($this->u)->getJson('/api/v1/trust/reconciliations')->assertForbidden();
    }
}
