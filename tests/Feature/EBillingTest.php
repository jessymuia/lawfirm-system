<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\EBilling\Ledes1998B;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-13: UTBMS codes on work and invoice lines, and the LEDES 1998B e-bill of an issued invoice. */
class EBillingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $u;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->u = $this->userWithPermissions([P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::LIST_TIME_ENTRIES, P::CREATE_TIME_ENTRIES,
            P::UPDATE_TIME_ENTRIES, P::MANAGE_ORGANISATION, P::LIST_CASES]);
        $this->u->forceFill(['name' => 'Achieng Otieno', 'timekeeper_class' => 'PT'])->save();
        $client = Client::factory()->create(['ebilling_client_id' => 'ACME-77']);
        $this->matter = Cases::factory()->create(['client_id' => $client->id, 'case_number' => 'HCCC 12/2026', 'assigned_to' => $this->u->id, 'client_matter_id' => 'CM-15']);
        $this->actingAs($this->u);
    }

    private function time(string $date, float $hours, ?string $task, ?string $activity, string $description = 'Drafting'): void
    {
        $this->postJson('/api/v1/time-entries', ['case_id' => $this->matter->id, 'description' => $description, 'date' => $date, 'hours' => $hours,
            'hourly_rate' => 10000, 'billable' => true, 'utbms_task_code' => $task, 'utbms_activity_code' => $activity])->assertCreated();
    }

    /** Two coded time entries billed into a draft, a court-fee expense line with VAT, then issued. */
    private function issuedInvoice(): Invoice
    {
        $this->time('04/05/2026', 2.5, 'L210', 'A103', 'Draft plaint | annexures [v2]');
        $this->time('06/05/2026', 1, 'L450', 'A109', 'Attend mention');
        $id = $this->postJson('/api/v1/time-entries/generate-invoice', ['case_id' => $this->matter->id, 'client_id' => $this->matter->client_id])->assertOk()->json('data.invoice_id');
        $this->postJson('/api/v1/invoiceItems', ['invoice_id' => $id, 'description' => 'Filing fees', 'quantity' => 1, 'rate' => 2000, 'tax' => 320, 'utbms_expense_code' => 'E112'])->assertOk();
        $this->putJson("/api/v1/send-to-admin/{$id}")->assertOk();

        return Invoice::findOrFail($id);
    }

    public function test_codes_are_offered_and_only_real_codes_are_accepted(): void
    {
        $codes = $this->getJson('/api/v1/utbms')->assertOk()->json('data');
        $this->assertSame('L110', $codes['tasks'][0]['code']);
        $this->assertSame('Case Assessment, Development and Administration', $codes['tasks'][0]['phase']);
        $this->assertContains('A106', array_column($codes['activities'], 'code'));
        $this->assertContains('E112', array_column($codes['expenses'], 'code'));

        $this->postJson('/api/v1/time-entries', ['case_id' => $this->matter->id, 'description' => 'x', 'date' => '04/05/2026', 'hours' => 1, 'hourly_rate' => 1,
            'utbms_task_code' => 'L100', 'utbms_activity_code' => 'A999'])->assertUnprocessable()->assertJsonValidationErrors(['utbms_task_code', 'utbms_activity_code']);
    }

    public function test_an_issued_invoice_exports_as_ledes_1998b(): void
    {
        $inv = $this->issuedInvoice();
        $this->assertSame('L210', DB::table('invoice_items')->where('invoice_id', $inv->id)->orderBy('id')->value('utbms_task_code'), 'codes travel from time to the bill');

        $this->getJson("/api/v1/invoices/{$inv->id}/ledes/check")->assertOk()->assertJsonPath('data.errors.0', fn ($e) => str_contains($e, 'law firm ID'));
        $this->get("/api/v1/invoices/{$inv->id}/ledes")->assertUnprocessable();
        $this->putJson('/api/v1/ebilling/settings', ['law_firm_id' => 'P051234567X'])->assertOk();
        $this->getJson("/api/v1/invoices/{$inv->id}/ledes/check")->assertOk()->assertJsonPath('data.errors', [])->assertJsonPath('data.lines', 4);

        $res = $this->get("/api/v1/invoices/{$inv->id}/ledes")->assertOk();
        $this->assertStringContainsString('attachment; filename="LEDES1998B-', $res->headers->get('Content-Disposition'));
        $rows = explode("\r\n", rtrim($res->getContent()));
        $this->assertSame('LEDES1998B[]', $rows[0]);
        $this->assertStringStartsWith('INVOICE_DATE|INVOICE_NUMBER|CLIENT_ID|', $rows[1]);
        $this->assertCount(6, $rows);
        $lines = array_map(fn ($r) => array_combine(Ledes1998B::FIELDS, explode('|', substr($r, 0, -2))), array_slice($rows, 2));

        [$fee, $attend, $expense, $tax] = $lines;
        $this->assertSame(['F', '2.5', '10000.00', '25000.00', '20260504', 'L210', 'A103', 'PT', 'Achieng Otieno', 'Draft plaint annexures (v2)'],
            [$fee['EXP/FEE/INV_ADJ_TYPE'], $fee['LINE_ITEM_NUMBER_OF_UNITS'], $fee['LINE_ITEM_UNIT_COST'], $fee['LINE_ITEM_TOTAL'], $fee['LINE_ITEM_DATE'],
                $fee['LINE_ITEM_TASK_CODE'], $fee['LINE_ITEM_ACTIVITY_CODE'], $fee['TIMEKEEPER_CLASSIFICATION'], $fee['TIMEKEEPER_NAME'], $fee['LINE_ITEM_DESCRIPTION']]);
        $this->assertSame('L450', $attend['LINE_ITEM_TASK_CODE']);
        $this->assertSame(['E', 'E112', '2000.00', ''], [$expense['EXP/FEE/INV_ADJ_TYPE'], $expense['LINE_ITEM_EXPENSE_CODE'], $expense['LINE_ITEM_TOTAL'], $expense['LINE_ITEM_TASK_CODE']]);
        $this->assertSame(['IF', '320.00', '320.00'], [$tax['EXP/FEE/INV_ADJ_TYPE'], $tax['LINE_ITEM_ADJUSTMENT_AMOUNT'], $tax['LINE_ITEM_TOTAL']]);

        foreach ($lines as $i => $l) {
            $this->assertSame((string) ($i + 1), $l['LINE_ITEM_NUMBER']);
            $this->assertSame('37320.00', $l['INVOICE_TOTAL'], 'the sum of the line totals, and the invoice total');
            // the expense line is dated with the invoice, and every line date must fall inside the billing period
            $this->assertSame(['ACME-77', 'HCCC 12/2026', 'CM-15', 'P051234567X', '20260504', Carbon::parse($inv->invoice_date)->format('Ymd')],
                [$l['CLIENT_ID'], $l['LAW_FIRM_MATTER_ID'], $l['CLIENT_MATTER_ID'], $l['LAW_FIRM_ID'], $l['BILLING_START_DATE'], $l['BILLING_END_DATE']]);
        }
        $this->assertEquals(37320, (float) $inv->fresh()->total_amount);
    }

    public function test_lines_without_codes_and_drafts_are_refused_with_reasons(): void
    {
        $this->putJson('/api/v1/ebilling/settings', ['law_firm_id' => 'P051234567X'])->assertOk();
        $this->time('04/05/2026', 1, null, null);
        $id = $this->postJson('/api/v1/time-entries/generate-invoice', ['case_id' => $this->matter->id, 'client_id' => $this->matter->client_id])->json('data.invoice_id');

        $errors = $this->getJson("/api/v1/invoices/{$id}/ledes/check")->assertOk()->json('data.errors');
        $this->assertStringContainsString('Submit it first', $errors[0]);
        $this->assertStringContainsString('UTBMS task and activity code', $errors[1]);
        $this->putJson("/api/v1/send-to-admin/{$id}")->assertOk();
        $this->get("/api/v1/invoices/{$id}/ledes")->assertUnprocessable()->assertJsonPath('data.errors.0', fn ($e) => str_contains($e, 'Line 1'));

        // missing classification and client matter id are warnings, not blockers
        $this->u->forceFill(['timekeeper_class' => null])->save();
        DB::table('cases')->where('id', $this->matter->id)->update(['client_matter_id' => null]);
        $this->assertCount(2, $this->getJson("/api/v1/invoices/{$id}/ledes/check")->json('data.warnings'));
    }

    public function test_the_identifiers_are_kept_on_their_records(): void
    {
        $this->putJson('/api/v1/ebilling/settings', ['law_firm_id' => 'bad|id'])->assertUnprocessable();
        $this->actingAs($this->userWithPermissions([P::LIST_INVOICES]))->putJson('/api/v1/ebilling/settings', ['law_firm_id' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/ebilling/settings')->assertOk()->assertJsonPath('data.law_firm_id', null);
        $this->assertSame('ACME-77', Client::find($this->matter->client_id)->ebilling_client_id);
    }
}
