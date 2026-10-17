<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\SequenceService;
use App\Support\FirmSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** DATA-8: gap-free, per-tenant, format-configurable numbering. */
class NumberingTest extends TestCase
{
    use RefreshDatabase;

    private function invoiceAttrs(?int $caseId = null): array
    {
        $case = $caseId ? null : Cases::factory()->create();

        return [
            'case_id' => $caseId ?? $case->id,
            'client_id' => $case?->client_id ?? Cases::find($caseId)->client_id,
            'invoice_date' => now()->format('Y-m-d'),
            'invoice_due_date' => now()->format('Y-m-d'),
            'workflow_status' => 'draft',
        ];
    }

    public function test_a_failed_insert_does_not_burn_an_invoice_number(): void
    {
        $first = Invoice::create($this->invoiceAttrs());
        $this->assertSame('INV-000001', $first->invoice_number);

        // The number is drawn, then the INSERT fails on a bad foreign key.
        $bad = $this->invoiceAttrs();
        $bad['case_id'] = 999999;
        try {
            Invoice::create($bad);
            $this->fail('the insert should have failed');
        } catch (QueryException $e) {
            // expected
        }

        $second = Invoice::create($this->invoiceAttrs());
        $this->assertSame('INV-000002', $second->invoice_number, 'a failed insert left a gap');
        $this->assertSame(2, (int) DB::table('sequences')->where('name', 'invoice')->value('last_value'));
    }

    public function test_the_firms_own_prefix_is_used_and_an_invalid_one_is_ignored(): void
    {
        FirmSettings::set('numbering.prefix.invoice', 'BILL');
        $this->assertSame('BILL-000001', Invoice::create($this->invoiceAttrs())->invoice_number);

        // the run continues whatever the prefix; a prefix the settings form would refuse falls back to the default
        FirmSettings::set('numbering.prefix.invoice', 'bad prefix!');
        $this->assertSame('INV-000002', Invoice::create($this->invoiceAttrs())->invoice_number);
    }

    public function test_a_failed_insert_does_not_burn_a_receipt_number(): void
    {
        $invoice = Invoice::create($this->invoiceAttrs());
        $userId = User::factory()->create()->id;
        $ok = fn () => Payment::create(['invoice_id' => $invoice->id, 'amount' => 1, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash', 'received_by' => $userId]);

        $this->assertSame('RCT-000001', $ok()->receipt_number);

        try {
            Payment::create(['invoice_id' => 999999, 'amount' => 1, 'payment_date' => now()->format('Y-m-d'), 'method' => 'cash', 'received_by' => $userId]);
            $this->fail('the insert should have failed');
        } catch (QueryException $e) {
            // expected
        }

        $this->assertSame('RCT-000002', $ok()->receipt_number);
    }

    public function test_numbering_continues_from_existing_data(): void
    {
        $attrs = $this->invoiceAttrs();
        DB::table('invoices')->insert($attrs + ['invoice_number' => 'INV-000041', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('INV-000042', Invoice::nextInvoiceNumber());
        $this->assertSame('INV-000043', Invoice::nextInvoiceNumber());
    }

    public function test_per_year_numbering_resets_each_year(): void
    {
        config(['invoicing.number_per_year' => true]);

        Carbon::setTestNow('2026-12-31 10:00:00');
        $this->assertSame('INV-2026-000001', Invoice::nextInvoiceNumber());
        $this->assertSame('INV-2026-000002', Invoice::nextInvoiceNumber());

        Carbon::setTestNow('2027-01-01 09:00:00');
        $this->assertSame('INV-2027-000001', Invoice::nextInvoiceNumber());

        Carbon::setTestNow('2026-12-31 23:00:00'); // an earlier year's series is untouched
        $this->assertSame('INV-2026-000003', Invoice::nextInvoiceNumber());

        Carbon::setTestNow();
    }

    public function test_invoice_and_receipt_series_are_independent(): void
    {
        $this->assertSame('INV-000001', Invoice::nextInvoiceNumber());
        $this->assertSame('RCT-000001', Payment::nextReceiptNumber());
        $this->assertSame('INV-000002', Invoice::nextInvoiceNumber());
    }

    public function test_generic_sequence_service_is_still_strictly_increasing(): void
    {
        $seq = app(SequenceService::class);

        $this->assertSame([1, 2, 3], [$seq->next('x'), $seq->next('x'), $seq->next('x')]);
    }
}
