<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoiceItemController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\TrustTransactionController;
use App\Http\Requests;
use App\Models\Cases;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * The money controllers check the permission again inside each action, behind the route's own `authz:permission`
 * (ENG-11). Over HTTP the route always answers first, so this calls the actions directly: a route that someday
 * forgets its declaration must still not let anyone touch invoices, payments or the trust ledger.
 */
class MoneyControllerGuardsTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    /** @return iterable<string, array{class-string, string, \Closure(): array}> */
    public static function guardedActions(): iterable
    {
        $req = fn (string $class) => fn () => [new $class];
        $reqId = fn (string $class) => fn () => [new $class, 1];
        $id = fn () => [1];

        yield 'invoice index' => [InvoiceController::class, 'index', fn () => []];
        yield 'invoice store' => [InvoiceController::class, 'store', $req(Requests\Invoice\StoreRequest::class)];
        yield 'invoice show' => [InvoiceController::class, 'show', $id];
        yield 'invoice update' => [InvoiceController::class, 'update', $reqId(Requests\Invoice\UpdateRequest::class)];
        yield 'invoice send to admin' => [InvoiceController::class, 'sendToAdmin', $id];
        yield 'invoice preview' => [InvoiceController::class, 'preview', $id];
        yield 'invoice item store' => [InvoiceItemController::class, 'store', $req(Requests\InvoiceItem\StoreRequest::class)];
        yield 'invoice item show' => [InvoiceItemController::class, 'show', $id];
        yield 'invoice item update' => [InvoiceItemController::class, 'update', $reqId(Requests\InvoiceItem\UpdateRequest::class)];
        yield 'invoice item destroy' => [InvoiceItemController::class, 'destroy', $id];
        yield 'payment index' => [PaymentController::class, 'index', fn () => []];
        yield 'payment store' => [PaymentController::class, 'store', $req(Requests\Payment\StoreRequest::class)];
        yield 'payment show' => [PaymentController::class, 'show', $id];
        yield 'payment destroy' => [PaymentController::class, 'destroy', $id];
        yield 'trust index' => [TrustTransactionController::class, 'index', $req(Requests\TrustTransaction\IndexRequest::class)];
        yield 'trust store' => [TrustTransactionController::class, 'store', $req(Requests\TrustTransaction\StoreRequest::class)];
        yield 'trust void' => [TrustTransactionController::class, 'void', fn () => [new Request, 1]];
    }

    /** @dataProvider guardedActions */
    public function test_the_action_refuses_without_its_permission_even_if_the_route_forgot(string $controller, string $action, \Closure $args): void
    {
        $this->actingAs($this->userWithPermissions([P::LIST_CLIENTS]));

        try {
            app($controller)->{$action}(...$args());
            $this->fail("{$controller}::{$action} ran without its permission");
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_bulk_delete_removes_only_what_the_caller_may_and_reports_the_rest(): void
    {
        // may create (so may bulk delete at all) but not delete: only their own drafts go
        $clerk = $this->userWithPermissions([P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES]);
        $colleague = $this->userWithPermissions([P::LIST_INVOICES, P::CREATE_INVOICES, P::UPDATE_INVOICES]);

        $draft = $this->draftFor($clerk);
        $submitted = $this->draftFor($clerk);
        $this->actingAs($clerk)->putJson("/api/v1/send-to-admin/{$submitted->id}")->assertOk();
        $theirs = $this->draftFor($colleague);

        $res = $this->actingAs($clerk)->postJson('/api/v1/invoices/bulk-delete', ['ids' => [$draft->id, $submitted->id, $theirs->id]])->assertOk();

        $this->assertSame([$draft->id], $res->json('data.deleted'));
        $this->assertEqualsCanonicalizing([$submitted->id, $theirs->id], $res->json('data.skipped'));
        $this->assertSoftDeleted('invoices', ['id' => $draft->id]);
        $this->assertNotSoftDeleted('invoices', ['id' => $submitted->id]);
        $this->assertNotSoftDeleted('invoices', ['id' => $theirs->id]);
    }

    private function draftFor($user): Invoice
    {
        $case = Cases::factory()->create();
        $id = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'case_id' => $case->id, 'client_id' => $case->client_id,
            'invoice_date' => '01/10/2026', 'invoice_due_date' => '31/10/2026',
        ])->assertCreated()->json('data.id');

        return Invoice::findOrFail($id);
    }
}
