<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * DATA-4: money must be exact decimal, not float-imprecise.
 * DATA-7: soft-delete must actually stamp deleted_by, not just set
 * deleted_at.
 * Invoice/case status transitions: workflow_status
 * (draft -> submitted -> void) and payment_status
 * (unpaid -> partially_paid -> paid) must move only as expected.
 */
class DomainTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function makeSubmittedInvoice(float $rate, float $quantity, float $tax = 0): Invoice
    {
        $case = Cases::factory()->create();
        $invoice = Invoice::create([
            'case_id' => $case->id,
            'client_id' => $case->client_id,
            'invoice_date' => now()->format('Y-m-d'),
            'invoice_due_date' => now()->addDays(30)->format('Y-m-d'),
            // DATA-10: line items can only be added while the invoice is a
            // draft; it is submitted afterwards, exactly as the app does.
            'workflow_status' => 'draft',
            'created_by' => 1,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'description' => 'Line item',
            'quantity' => $quantity,
            'rate' => $rate,
            'tax' => $tax,
            'total_amount' => ($rate * $quantity) + $tax,
            'created_by' => 1,
        ]);

        $invoice->refreshTotals();
        $invoice->update(['workflow_status' => 'submitted']);

        return $invoice->fresh();
    }

    public function test_invoice_totals_use_exact_decimal_precision(): void
    {
        // DATA-4: values chosen specifically because they are classic
        // floating-point-imprecision triggers (0.1 + 0.2 != 0.3 in
        // binary float, but is exact in DECIMAL).
        $invoice = $this->makeSubmittedInvoice(rate: 33.33, quantity: 3, tax: 0.01);

        $this->assertEquals('99.99', (string) $invoice->subtotal);
        $this->assertEquals('0.01', (string) $invoice->tax_total);
        $this->assertEquals('100.00', (string) $invoice->total_amount);
    }

    public function test_payment_status_moves_from_unpaid_to_partially_paid_to_paid(): void
    {
        $user = $this->userWithPermissions(['create-payments']);
        $invoice = $this->makeSubmittedInvoice(rate: 100, quantity: 1);

        $this->assertEquals('unpaid', $invoice->payment_status);

        $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'payment_date' => now()->format('d/m/Y'),
            'method' => 'cash',
        ])->assertStatus(200);

        $this->assertEquals('partially_paid', $invoice->fresh()->payment_status);

        $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 50,
            'payment_date' => now()->format('d/m/Y'),
            'method' => 'cash',
        ])->assertStatus(200);

        $this->assertEquals('paid', $invoice->fresh()->payment_status);
        $this->assertEquals('100.00', (string) $invoice->fresh()->amount_paid);
    }

    public function test_payment_exceeding_outstanding_balance_is_rejected(): void
    {
        $user = $this->userWithPermissions(['create-payments']);
        $invoice = $this->makeSubmittedInvoice(rate: 100, quantity: 1);

        $response = $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 150,
            'payment_date' => now()->format('d/m/Y'),
            'method' => 'cash',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('0.00', (string) $invoice->fresh()->amount_paid);
    }

    public function test_payments_cannot_be_recorded_against_a_draft_invoice(): void
    {
        $user = $this->userWithPermissions(['create-payments']);
        $case = Cases::factory()->create();
        $invoice = Invoice::create([
            'case_id' => $case->id,
            'client_id' => $case->client_id,
            'invoice_date' => now()->format('Y-m-d'),
            'invoice_due_date' => now()->addDays(30)->format('Y-m-d'),
            'workflow_status' => 'draft',
            'created_by' => 1,
        ]);

        $response = $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 10,
            'payment_date' => now()->format('d/m/Y'),
            'method' => 'cash',
        ]);

        $response->assertStatus(422);
    }

    public function test_voiding_a_payment_reverses_the_invoice_payment_status(): void
    {
        $user = $this->userWithPermissions(['create-payments', 'delete-payments']);
        $invoice = $this->makeSubmittedInvoice(rate: 100, quantity: 1);

        $paymentResponse = $this->actingAs($user)->postJson('/api/v1/payments', [
            'invoice_id' => $invoice->id,
            'amount' => 100,
            'payment_date' => now()->format('d/m/Y'),
            'method' => 'cash',
        ]);
        $paymentResponse->assertStatus(200);
        $this->assertEquals('paid', $invoice->fresh()->payment_status);

        $payment = Payment::where('invoice_id', $invoice->id)->firstOrFail();
        $this->actingAs($user)->deleteJson("/api/v1/payments/{$payment->id}")->assertStatus(200);

        $this->assertEquals('unpaid', $invoice->fresh()->payment_status);
        $this->assertEquals('0.00', (string) $invoice->fresh()->amount_paid);
    }

    public function test_soft_deleting_a_case_stamps_deleted_by(): void
    {
        // DATA-7: destroy() must stamp deleted_by, not just soft-delete
        // via deleted_at with no record of who did it.
        $user = $this->userWithPermissions(['create-cases', 'delete-cases']);
        $case = Cases::factory()->create();

        $this->actingAs($user)->deleteJson("/api/v1/cases/{$case->id}")->assertStatus(200);

        $this->assertSoftDeleted('cases', ['id' => $case->id]);
        $this->assertEquals($user->id, $case->fresh()->deleted_by);
    }

    public function test_soft_deleting_a_client_stamps_deleted_by(): void
    {
        $user = $this->userWithPermissions(['create-clients', 'delete-clients']);
        $client = Client::factory()->create();

        $this->actingAs($user)->deleteJson("/api/v1/clients/{$client->id}")->assertStatus(200);

        $this->assertSoftDeleted('clients', ['id' => $client->id]);
        $this->assertEquals($user->id, $client->fresh()->deleted_by);
    }
}
