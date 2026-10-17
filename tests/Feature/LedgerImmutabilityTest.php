<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TrustTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * DATA-10: the database itself must refuse to alter or delete ledger history.
 * Every attack below goes through the query builder / raw SQL, deliberately
 * bypassing Eloquent and the controllers, because that is the point: the
 * guarantee must hold even when application code is wrong.
 */
class LedgerImmutabilityTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function assertRefused(callable $attack, string $expected): void
    {
        try {
            $attack();
            $this->fail("The database allowed a forbidden change (expected: {$expected}).");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expected, $e->getMessage());
        }
    }

    private function draftInvoice(): Invoice
    {
        $case = Cases::factory()->create();
        $invoice = Invoice::create([
            'case_id' => $case->id,
            'client_id' => $case->client_id,
            'invoice_date' => now()->format('Y-m-d'),
            'invoice_due_date' => now()->addDays(30)->format('Y-m-d'),
            'workflow_status' => 'draft',
            'created_by' => 1,
        ]);
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Work', 'quantity' => 1,
            'rate' => 100, 'tax' => 0, 'total_amount' => 100, 'created_by' => 1,
        ]);
        $invoice->refreshTotals();

        return $invoice->fresh();
    }

    private function submittedInvoice(): Invoice
    {
        $invoice = $this->draftInvoice();
        $invoice->update(['workflow_status' => 'submitted']);

        return $invoice->fresh();
    }

    private function payment(Invoice $invoice, float $amount = 40): Payment
    {
        $user = $this->userWithPermissions(['create-payments']);
        $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id, 'amount' => $amount,
            'payment_date' => now()->format('d/m/Y'), 'method' => 'cash',
        ])->assertOk();

        return Payment::where('invoice_id', $invoice->id)->latest('id')->first();
    }

    private function trustDeposit(int $clientId, float $amount = 500): TrustTransaction
    {
        $user = $this->userWithPermissions([]);

        return TrustTransaction::post($clientId, null, 'deposit', $amount, 'Retainer', null, $user->id);
    }

    // ---- trust ledger ----------------------------------------------------

    public function test_trust_entries_cannot_be_edited_or_deleted(): void
    {
        $tx = $this->trustDeposit(Cases::factory()->create()->client_id);
        $row = fn () => DB::table('trust_transactions')->where('id', $tx->id);

        foreach (['amount' => 1, 'balance_after' => 1, 'description' => 'edited', 'type' => 'disbursement', 'reference_number' => 'x', 'recorded_by' => 999] as $col => $value) {
            $this->assertRefused(fn () => $row()->update([$col => $value]), 'append-only');
        }

        $this->assertRefused(fn () => $row()->delete(), 'append-only');
        $this->assertRefused(fn () => $tx->delete(), 'append-only');           // Eloquent soft delete
        $this->assertRefused(fn () => DB::statement('DELETE FROM trust_transactions'), 'append-only');

        $this->assertSame('500.00', (string) $tx->fresh()->amount);
    }

    public function test_trust_void_is_a_one_way_marker_only(): void
    {
        $tx = $this->trustDeposit(Cases::factory()->create()->client_id);
        $admin = $this->userWithPermissions(['void-trust-transactions']);

        $this->actingAs($admin)->putJson("/api/v1/trust-transactions/{$tx->id}/void")->assertOk();
        $this->assertNotNull($tx->fresh()->voided_at);

        $row = fn () => DB::table('trust_transactions')->where('id', $tx->id);
        $this->assertRefused(fn () => $row()->update(['voided_at' => null]), 'voided');
        $this->assertRefused(fn () => $row()->update(['voided_by' => null]), 'voided');
        $this->assertRefused(fn () => $row()->update(['amount' => 1]), 'append-only');
    }

    public function test_cached_trust_balance_reconciles_with_the_ledger(): void
    {
        $clientId = Cases::factory()->create()->client_id;
        $user = $this->userWithPermissions([]);

        TrustTransaction::post($clientId, null, 'deposit', 1000, 'Retainer', null, $user->id);
        TrustTransaction::post($clientId, null, 'disbursement', 250.50, 'Filing fee', null, $user->id);
        $last = TrustTransaction::post($clientId, null, 'deposit', 75.25, 'Top up', null, $user->id);

        $derived = TrustTransaction::balanceForClient($clientId);
        $this->assertSame(824.75, $derived);
        $this->assertSame($derived, (float) $last->fresh()->balance_after, 'cached balance_after must equal the balance derived from the ledger');
    }

    // ---- payments --------------------------------------------------------

    public function test_payments_cannot_be_edited_or_hard_deleted(): void
    {
        $payment = $this->payment($this->submittedInvoice());
        $row = fn () => DB::table('payments')->where('id', $payment->id);

        foreach (['amount' => 1, 'receipt_number' => 'X', 'method' => 'card', 'notes' => 'edited', 'invoice_id' => 999] as $col => $value) {
            $this->assertRefused(fn () => $row()->update([$col => $value]), 'append-only');
        }
        $this->assertRefused(fn () => $row()->delete(), 'append-only');
    }

    public function test_payment_void_is_one_way(): void
    {
        $payment = $this->payment($this->submittedInvoice());
        $admin = $this->userWithPermissions(['delete-payments']);

        $this->actingAs($admin)->deleteJson("/api/v1/payments/{$payment->id}")->assertOk(); // soft void allowed
        $this->assertNotNull(Payment::withTrashed()->find($payment->id)->deleted_at);

        $row = fn () => DB::table('payments')->where('id', $payment->id);
        $this->assertRefused(fn () => $row()->update(['deleted_at' => null]), 'voided');
        $this->assertRefused(fn () => $row()->update(['deleted_by' => null]), 'voided');
    }

    // ---- invoices --------------------------------------------------------

    public function test_invoices_are_never_hard_deleted_even_drafts(): void
    {
        $invoice = $this->draftInvoice();

        $this->assertRefused(fn () => DB::table('invoices')->where('id', $invoice->id)->delete(), 'append-only');
    }

    public function test_invoice_number_never_changes(): void
    {
        $invoice = $this->draftInvoice();

        $this->assertRefused(fn () => DB::table('invoices')->where('id', $invoice->id)->update(['invoice_number' => 'INV-999999']), 'invoice number');
    }

    public function test_draft_invoices_stay_editable_and_submitted_ones_are_frozen(): void
    {
        $invoice = $this->draftInvoice();
        DB::table('invoices')->where('id', $invoice->id)->update(['total_amount' => 150, 'invoice_due_date' => now()->addDays(60)->format('Y-m-d')]); // allowed while draft

        $invoice->update(['workflow_status' => 'submitted']);
        $row = fn () => DB::table('invoices')->where('id', $invoice->id);

        foreach (['total_amount' => 1, 'subtotal' => 1, 'tax_total' => 1, 'currency' => 'USD', 'client_id' => 999, 'invoice_date' => '2000-01-01'] as $col => $value) {
            $this->assertRefused(fn () => $row()->update([$col => $value]), 'frozen');
        }

        // The payment cache is derived data and stays writable.
        $row()->update(['amount_paid' => 10, 'payment_status' => 'partially_paid']);
        $this->assertSame('partially_paid', $invoice->fresh()->payment_status);
    }

    public function test_workflow_only_moves_forward(): void
    {
        $submitted = $this->submittedInvoice();
        $row = fn (Invoice $i) => DB::table('invoices')->where('id', $i->id);

        $this->assertRefused(fn () => $row($submitted)->update(['workflow_status' => 'draft']), 'workflow');

        $row($submitted)->update(['workflow_status' => 'void']);
        $this->assertRefused(fn () => $row($submitted)->update(['workflow_status' => 'submitted']), 'workflow');
        $this->assertRefused(fn () => $row($submitted)->update(['workflow_status' => 'draft']), 'workflow');

        $draft = $this->draftInvoice();
        $row($draft)->update(['workflow_status' => 'void']); // draft -> void allowed
        $this->assertSame('void', $draft->fresh()->workflow_status);
    }

    // ---- invoice items ---------------------------------------------------

    public function test_items_of_an_issued_invoice_are_frozen_at_the_database(): void
    {
        $invoice = $this->submittedInvoice();
        $item = InvoiceItem::where('invoice_id', $invoice->id)->first();

        $this->assertRefused(fn () => DB::table('invoice_items')->where('id', $item->id)->update(['rate' => 1]), 'frozen');
        $this->assertRefused(fn () => DB::table('invoice_items')->where('id', $item->id)->delete(), 'cannot be deleted');
        $this->assertRefused(fn () => InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => 'Sneaky', 'quantity' => 1, 'rate' => 1, 'tax' => 0, 'total_amount' => 1, 'created_by' => 1,
        ]), 'cannot be added');
    }

    public function test_item_endpoints_return_422_for_issued_invoices_and_work_for_drafts(): void
    {
        $user = $this->userWithPermissions(['create-invoice', 'update-invoice', 'delete-invoice']);
        $issued = $this->submittedInvoice();
        $issuedItem = InvoiceItem::where('invoice_id', $issued->id)->first();

        $payload = fn (Invoice $i) => ['invoice_id' => $i->id, 'description' => 'x', 'quantity' => 1, 'rate' => 5, 'tax' => 0];

        $this->actingAs($user)->postJson('/api/v1/invoiceItems', $payload($issued))->assertStatus(422);
        $this->actingAs($user)->putJson("/api/v1/invoiceItems/{$issuedItem->id}", $payload($issued))->assertStatus(422);
        $this->actingAs($user)->deleteJson("/api/v1/invoiceItems/{$issuedItem->id}")->assertStatus(422);

        // A client cannot dodge the check by naming a different (draft) invoice in the body.
        $draft = $this->draftInvoice();
        $this->actingAs($user)->putJson("/api/v1/invoiceItems/{$issuedItem->id}", $payload($draft))->assertStatus(422);
        $this->assertEquals(100, $issuedItem->fresh()->rate);

        $this->actingAs($user)->postJson('/api/v1/invoiceItems', $payload($draft))->assertOk();
    }

    // ---- housekeeping ----------------------------------------------------

    public function test_all_guard_triggers_are_installed(): void
    {
        $count = DB::getDriverName() === 'sqlite'
            ? DB::table('sqlite_master')->where('type', 'trigger')->where('name', 'like', 'ledger_guard_%')->count()
            : DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('TRIGGER_NAME', 'like', 'ledger_guard_%')->count();

        // 3 trust + 3 payments + 4 invoices + 3 invoice_items
        $this->assertSame(13, $count);
    }
}
