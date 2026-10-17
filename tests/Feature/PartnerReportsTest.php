<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Client;
use App\Models\ExpenseCategory;
use App\Models\Invoice;
use App\Models\Office;
use App\Models\PracticeGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * FUN-17: partner reports over May 2026 (21 working days = 147 available hours at 7h/day).
 *
 * Matter M1 (Nairobi, Litigation, lawyer L1): 10h@100 billed on an invoice line charging 900 (realisation 0.9),
 * 4h@100 unbilled, 2h non-billable; invoice 900 due 20 May, 500 paid on 25 May; 50 of expenses.
 * Matter M2 (Mombasa, lawyer L2): 3h@200 unbilled from 10 April (WIP aged 31-60).
 */
class PartnerReportsTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private const PERIOD = 'from=2026-05-01&to=2026-05-31';

    private array $f = [];

    protected function setUp(): void
    {
        parent::setUp();
        $nbo = Office::create(['name' => 'Nairobi']);
        $msa = Office::create(['name' => 'Mombasa']);
        $lit = PracticeGroup::create(['name' => 'Litigation']);
        $l1 = User::factory()->create(['name' => 'Lawyer One', 'office_id' => $nbo->id, 'practice_group_id' => $lit->id]);
        $l2 = User::factory()->create(['name' => 'Lawyer Two', 'office_id' => $msa->id]);
        $m1 = Cases::factory()->create(['case_number' => 'M1', 'assigned_to' => $l1->id, 'office_id' => $nbo->id, 'practice_group_id' => $lit->id, 'client_id' => Client::factory()->create(['name' => 'Client One'])->id]);
        $m2 = Cases::factory()->create(['case_number' => 'M2', 'assigned_to' => $l2->id, 'office_id' => $msa->id]);

        $inv = Invoice::create(['case_id' => $m1->id, 'client_id' => $m1->client_id, 'invoice_date' => '2026-05-10', 'invoice_due_date' => '2026-05-20', 'workflow_status' => 'draft']);
        $item = DB::table('invoice_items')->insertGetId(['invoice_id' => $inv->id, 'description' => 'Fees', 'quantity' => 1, 'rate' => 900, 'tax' => 0, 'total_amount' => 900, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('invoices')->where('id', $inv->id)->update(['workflow_status' => 'submitted', 'subtotal' => 900, 'total_amount' => 900]);

        $time = fn ($case, $user, $date, $hours, $rate, $billable, $itemId = null) => DB::table('time_entries')->insert([
            'case_id' => $case, 'user_id' => $user, 'description' => 'Work', 'date' => $date, 'hours' => $hours, 'hourly_rate' => $rate,
            'billable' => $billable, 'billed' => $itemId !== null, 'invoice_item_id' => $itemId, 'created_at' => now(), 'updated_at' => now()]);
        $time($m1->id, $l1->id, '2026-05-04', 10, 100, true, $item);
        $time($m1->id, $l1->id, '2026-05-05', 4, 100, true);
        $time($m1->id, $l1->id, '2026-05-06', 2, 100, false);
        $time($m2->id, $l2->id, '2026-04-10', 3, 200, true);

        DB::table('payments')->insert(['invoice_id' => $inv->id, 'receipt_number' => 'RCT-1', 'amount' => 500, 'payment_date' => '2026-05-25', 'method' => 'cash', 'received_by' => $l1->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('expenses')->insert(['case_id' => $m1->id, 'date' => '2026-05-15', 'amount' => 50, 'category' => ExpenseCategory::factory()->create()->id, 'description' => 'Filing',
            'vendor' => 'Court', 'payment_method' => 'cash', 'user_id' => $l1->id, 'created_at' => now(), 'updated_at' => now()]);

        $this->f = compact('nbo', 'msa', 'lit', 'l1', 'l2', 'm1', 'm2');
        $this->actingAs($this->userWithPermissions([P::VIEW_PARTNER_REPORTS, P::ACCESS_ALL_MATTERS]));
    }

    public function test_utilisation_counts_billable_hours_against_working_days(): void
    {
        $rows = collect($this->getJson('/api/v1/reports/utilisation?'.self::PERIOD)->assertOk()->json('data'))->keyBy('lawyer');
        $this->assertEquals(16, $rows['Lawyer One']['hours']);
        $this->assertEquals(14, $rows['Lawyer One']['billable_hours']);
        $this->assertEquals(147, $rows['Lawyer One']['available_hours']);
        $this->assertEqualsWithDelta(14 / 147, $rows['Lawyer One']['utilisation'], 0.0001);
        $this->assertArrayNotHasKey('Lawyer Two', $rows->all(), 'no time in the period');
    }

    public function test_realisation_compares_charged_with_standard_value_and_collected_with_invoiced(): void
    {
        $this->getJson('/api/v1/reports/realisation?'.self::PERIOD)->assertOk()
            ->assertJsonPath('data.standard_value_billed', 1000)
            ->assertJsonPath('data.amount_charged', 900)
            ->assertJsonPath('data.billing_realisation', 0.9)
            ->assertJsonPath('data.fees_invoiced', 900)
            ->assertJsonPath('data.collected', 500)
            ->assertJsonPath('data.collection_realisation', 0.5556);
    }

    public function test_wip_and_receivables_are_aged_as_at_the_end_of_the_period(): void
    {
        $wip = $this->getJson('/api/v1/reports/wip?'.self::PERIOD)->assertOk()->json('data');
        $this->assertEquals(1000, $wip['total']);
        $this->assertEquals(400, $wip['buckets']['0-30']);
        $this->assertEquals(600, $wip['buckets']['31-60']);
        $this->assertSame('M2', $wip['matters'][0]['case_number'], 'biggest first');

        $ar = $this->getJson('/api/v1/reports/receivables?'.self::PERIOD)->assertOk()->json('data');
        $this->assertEquals(400, $ar['total']);
        $this->assertEquals(400, $ar['buckets']['1-30']);
        $this->assertSame(11, $ar['invoices'][0]['days_overdue']);

        // before the payment, all 900 was outstanding
        $this->assertEquals(900, $this->getJson('/api/v1/reports/receivables?from=2026-05-01&to=2026-05-24')->json('data.total'));
    }

    public function test_matter_profitability_and_its_roll_ups(): void
    {
        $rows = collect($this->getJson('/api/v1/reports/profitability?'.self::PERIOD)->assertOk()->json('data'))->keyBy('case_number');
        $this->assertEquals(['hours' => 16, 'invoiced' => 900, 'collected' => 500, 'expenses' => 50, 'wip' => 400, 'contribution' => 450],
            array_intersect_key($rows['M1'], array_flip(['hours', 'invoiced', 'collected', 'expenses', 'wip', 'contribution'])));
        $this->assertEquals(600, $rows['M2']['wip']);

        $byOffice = collect($this->getJson('/api/v1/reports/profitability?'.self::PERIOD.'&group_by=office')->json('data'))->keyBy('group');
        $this->assertEquals(450, $byOffice['Nairobi']['contribution']);
        $this->assertEquals(600, $byOffice['Mombasa']['wip']);
        $byLawyer = collect($this->getJson('/api/v1/reports/profitability?'.self::PERIOD.'&group_by=lawyer')->json('data'))->keyBy('group');
        $this->assertSame(1, $byLawyer['Lawyer One']['matters']);

        // narrowed to one office
        $only = $this->getJson('/api/v1/reports/profitability?'.self::PERIOD.'&office_id='.$this->f['msa']->id)->json('data');
        $this->assertSame(['M2'], array_column($only, 'case_number'));

        $csv = $this->get('/api/v1/reports/profitability?'.self::PERIOD.'&format=csv')->assertOk()->streamedContent();
        $this->assertStringStartsWith('case_number,client,lawyer', $csv);
        $this->assertStringContainsString('M1,"Client One","Lawyer One"', $csv);
    }

    public function test_the_summary_brings_the_headlines_together(): void
    {
        $this->getJson('/api/v1/reports/summary?'.self::PERIOD)->assertOk()
            ->assertJsonPath('data.billable_hours', 14)
            ->assertJsonPath('data.realisation.billing_realisation', 0.9)
            ->assertJsonPath('data.wip.total', 1000)
            ->assertJsonPath('data.receivables.total', 400);
    }

    public function test_a_partner_sees_only_figures_from_matters_they_can_open(): void
    {
        config(['matters.access' => 'restricted']);
        // can see M1 only (assigned to it)
        $partner = $this->userWithPermissions([P::VIEW_PARTNER_REPORTS]);
        DB::table('cases')->where('id', $this->f['m1']->id)->update(['assigned_to' => $partner->id]);
        $this->actingAs($partner);

        $rows = $this->getJson('/api/v1/reports/profitability?'.self::PERIOD)->json('data');
        $this->assertSame(['M1'], array_column($rows, 'case_number'));
        $this->assertEquals(400, $this->getJson('/api/v1/reports/wip?'.self::PERIOD)->json('data.total'));
    }

    public function test_period_rules_and_permission(): void
    {
        $this->getJson('/api/v1/reports/summary?from=2026-05-31&to=2026-05-01')->assertUnprocessable();
        $this->getJson('/api/v1/reports/summary?from=2020-01-01&to=2026-05-01')->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->getJson('/api/v1/reports/profitability?'.self::PERIOD.'&group_by=client')->assertUnprocessable();

        $this->actingAs($this->userWithPermissions([P::LIST_CASES]))->getJson('/api/v1/reports/summary?'.self::PERIOD)->assertForbidden();
    }
}
