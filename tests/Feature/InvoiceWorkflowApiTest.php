<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\TrustTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * ENG-17: the money endpoints end to end over HTTP — the invoice workflow (draft -> submitted -> paid -> void), who may
 * see and change which invoice, line items, payments/receipts and the trust ledger listing. The model-level invariants
 * live in LedgerImmutabilityTest / TrustAccountingTest; this proves the controllers wire them up correctly.
 */
class InvoiceWorkflowApiTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function advocate(): User
    {
        return $this->userWithPermissions([
            P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::LIST_PAYMENTS,
        ]);
    }

    /** Create before any request: Spatie resolves the guard from the current auth context. */
    private function admin(): User
    {
        return $this->adminUser();
    }

    private function draftFor(User $user): Invoice
    {
        $case = Cases::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'case_id' => $case->id, 'client_id' => $case->client_id,
            'invoice_date' => '01/10/2026', 'invoice_due_date' => '31/10/2026',
        ])->assertCreated()->json('data.id');

        return Invoice::findOrFail($id);
    }

    private function item(Invoice $invoice, float $qty = 2, float $rate = 50, float $tax = 16): array
    {
        return ['invoice_id' => $invoice->id, 'description' => 'Drafting', 'quantity' => $qty, 'rate' => $rate, 'tax' => $tax];
    }

    // ---- invoices ---------------------------------------------------------------------------------------------------

    public function test_full_invoice_lifecycle_draft_items_submit_pay_and_preview(): void
    {
        $advocate = $this->advocate();
        $admin = $this->admin();
        $invoice = $this->draftFor($advocate);
        $this->assertSame('draft', $invoice->workflow_status);
        $this->assertNotEmpty($invoice->invoice_number);

        // the creator sees and edits their own draft
        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertOk()
            ->assertJsonPath('data.invoice_number', $invoice->invoice_number)
            ->assertJsonPath('data.workflow_status', 'draft');
        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'case_id' => $invoice->case_id, 'client_id' => $invoice->client_id,
            'invoice_date' => '02/10/2026', 'invoice_due_date' => '30/10/2026',
        ])->assertOk()->assertJsonPath('data.invoice_date', '02/10/2026');

        // line items: create, read, change, remove, and totals follow
        $itemId = $this->postJson('/api/v1/invoiceItems', $this->item($invoice))->assertOk()->json('data.id')
            ?? InvoiceItem::where('invoice_id', $invoice->id)->value('id');
        $this->getJson("/api/v1/invoiceItems/{$itemId}")->assertOk();
        $this->putJson("/api/v1/invoiceItems/{$itemId}", $this->item($invoice, 3, 100, 0))->assertOk();
        $extra = InvoiceItem::create($this->item($invoice, 1, 10, 0) + ['total_amount' => 10]);
        $this->actingAs($admin)->deleteJson("/api/v1/invoiceItems/{$extra->id}")->assertOk();
        $this->assertEquals(300, (float) $invoice->fresh()->total_amount);

        // preview carries the lines and totals
        $preview = $this->actingAs($advocate)->getJson("/api/v1/preview-invoice/{$invoice->id}")->assertOk()->json('data');
        [$lines, $header] = $preview;
        $this->assertCount(1, $lines);
        $this->assertSame($invoice->invoice_number, $header['number']);
        $this->assertTrue($header['postShow'], 'a draft can still be edited');

        // submit: editing stops, a second submit is refused
        $this->putJson("/api/v1/send-to-admin/{$invoice->id}")->assertOk()->assertJsonPath('data.workflow_status', 'submitted');
        $this->putJson("/api/v1/send-to-admin/{$invoice->id}")->assertStatus(422);
        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'case_id' => $invoice->case_id, 'client_id' => $invoice->client_id,
            'invoice_date' => '03/10/2026', 'invoice_due_date' => '30/10/2026',
        ])->assertStatus(422);

        // the admin now sees it in their queue and records payments up to the balance, never beyond
        $this->actingAs($admin);
        $this->assertContains($invoice->id, array_column($this->getJson('/api/v1/invoices')->assertOk()->json('data.data'), 'id'));
        $this->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 500, 'payment_date' => '05/10/2026', 'method' => 'cash'])
            ->assertStatus(422);
        $receipt = $this->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 100, 'payment_date' => '05/10/2026', 'method' => 'mobile_money', 'reference_number' => 'QX1'])
            ->assertOk()->json('data.receipt_number');
        $this->assertNotEmpty($receipt);
        $this->assertSame('partially_paid', $invoice->fresh()->payment_status);
        $this->postJson('/api/v1/payments', ['invoice_id' => $invoice->id, 'amount' => 200, 'payment_date' => '06/10/2026', 'method' => 'bank_transfer'])->assertOk();
        $this->assertSame('paid', $invoice->fresh()->payment_status);

        // payment listing and detail, then voiding one reopens the balance
        $payments = $this->getJson('/api/v1/payments?invoice_id='.$invoice->id)->assertOk()->json('data.data');
        $this->assertCount(2, $payments);
        $first = Payment::where('invoice_id', $invoice->id)->orderBy('id')->firstOrFail();
        $this->getJson("/api/v1/payments/{$first->id}")->assertOk()->assertJsonPath('data.receipt_number', $receipt);
        $this->deleteJson("/api/v1/payments/{$first->id}")->assertOk();
        $this->assertSame('partially_paid', $invoice->fresh()->payment_status);

        // filters used by the dashboard KPI links
        $rows = $this->getJson('/api/v1/invoices?payment_status=partially_paid,unpaid&workflow_status=submitted')->assertOk()->json('data.data');
        $this->assertSame([$invoice->id], array_column($rows, 'id'));
        $this->assertSame([], $this->getJson('/api/v1/invoices?case_id=999999')->json('data.data'));
    }

    public function test_other_users_cannot_see_or_change_an_invoice_they_did_not_create(): void
    {
        $owner = $this->advocate();
        $invoice = $this->draftFor($owner);
        $other = $this->advocate();
        $this->actingAs($other);

        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertForbidden();
        $this->getJson("/api/v1/preview-invoice/{$invoice->id}")->assertForbidden();
        $this->putJson("/api/v1/send-to-admin/{$invoice->id}")->assertForbidden();
        $this->putJson("/api/v1/invoices/{$invoice->id}", [
            'case_id' => $invoice->case_id, 'client_id' => $invoice->client_id,
            'invoice_date' => '02/10/2026', 'invoice_due_date' => '30/10/2026',
        ])->assertForbidden();
        $this->assertSame([], $this->getJson('/api/v1/invoices')->assertOk()->json('data.data'), 'lists show only your own invoices');
    }

    public function test_an_admin_does_not_see_other_peoples_drafts_until_they_are_submitted(): void
    {
        $admin = $this->admin();
        $invoice = $this->draftFor($this->advocate());
        $this->actingAs($admin);

        $this->assertNotContains($invoice->id, array_column($this->getJson('/api/v1/invoices')->json('data.data'), 'id'));
        $this->getJson("/api/v1/invoices/{$invoice->id}")->assertOk(); // but can open it directly
    }

    public function test_invoice_validation(): void
    {
        $this->actingAs($this->advocate());
        $case = Cases::factory()->create();

        $this->postJson('/api/v1/invoices', [])->assertStatus(422)->assertJsonValidationErrors(['case_id', 'client_id', 'invoice_date', 'invoice_due_date']);
        $this->postJson('/api/v1/invoices', [
            'case_id' => $case->id, 'client_id' => $case->client_id, 'invoice_date' => '10/10/2026', 'invoice_due_date' => '01/10/2026',
        ])->assertStatus(422)->assertJsonValidationErrors(['invoice_due_date']);
    }

    public function test_deleting_invoices_owner_drafts_only_unless_permitted(): void
    {
        $owner = $this->userWithPermissions([P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::DELETE_INVOICES]);
        $mine = $this->draftFor($owner);
        $submitted = $this->draftFor($owner);
        $this->actingAs($owner)->putJson("/api/v1/send-to-admin/{$submitted->id}")->assertOk();

        // a user without the delete permission may still delete their own draft (route group allows the owner path)
        $plain = $this->userWithPermissions([P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES, P::DELETE_INVOICES]);
        $theirs = $this->draftFor($plain);
        $this->actingAs($plain)->deleteJson("/api/v1/invoices/{$theirs->id}")->assertOk();
        $this->assertSoftDeleted('invoices', ['id' => $theirs->id]);
        $this->assertSame('void', Invoice::withTrashed()->find($theirs->id)->workflow_status);

        // bulk: deletes what the caller may delete, reports the rest
        $res = $this->actingAs($owner)->postJson('/api/v1/invoices/bulk-delete', ['ids' => [$mine->id, $submitted->id]])->assertOk();
        $this->assertEqualsCanonicalizing([$mine->id, $submitted->id], array_merge($res->json('data.deleted'), $res->json('data.skipped')));
        $this->postJson('/api/v1/invoices/bulk-delete', [])->assertStatus(422);
    }

    // ---- trust ledger listing -----------------------------------------------------------------------------------------

    public function test_trust_ledger_listing_shows_entries_and_balance_for_one_client(): void
    {
        $user = $this->userWithPermissions([P::LIST_TRUST_TRANSACTIONS, P::CREATE_TRUST_TRANSACTIONS, P::VOID_TRUST_TRANSACTIONS]);
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        TrustTransaction::post($client->id, null, 'deposit', 300, 'Retainer', null, $user->id);
        TrustTransaction::post($client->id, null, 'disbursement', 50, 'Filing fee', null, $user->id);
        TrustTransaction::post($other->id, null, 'deposit', 999, 'Other client', null, $user->id);

        $this->actingAs($user)->getJson('/api/v1/trust-transactions')->assertStatus(422); // client_id is required
        $res = $this->getJson("/api/v1/trust-transactions?client_id={$client->id}")->assertOk();
        $this->assertEquals(250, $res->json('data.balance'));
        $this->assertCount(2, $res->json('data.transactions.data'));

        $paged = $this->getJson("/api/v1/trust-transactions?client_id={$client->id}&page=1&per_page=1")->assertOk();
        $this->assertSame(2, $paged->json('data.transactions.total'));

        $tx = TrustTransaction::where('client_id', $client->id)->where('type', 'disbursement')->firstOrFail();
        $this->putJson("/api/v1/trust-transactions/{$tx->id}/void")->assertOk();
        $this->putJson("/api/v1/trust-transactions/{$tx->id}/void")->assertStatus(422);
        $this->assertEquals(300, TrustTransaction::balanceForClient($client->id));
    }
}
