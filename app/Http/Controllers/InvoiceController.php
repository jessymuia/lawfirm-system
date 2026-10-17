<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\DraftInvoice;
use App\Actions\Invoices\ReviseDraftInvoice;
use App\Actions\Invoices\SubmitInvoice;
use App\Actions\Invoices\VoidInvoice;
use App\Constants\ModulePermissions;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class InvoiceController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_INVOICES)) {
            abort(403);
        }

        $query = Invoice::with(['case', 'client'])->orderBy('id', 'desc');

        // UI-10/UI-35: lets a case's own Financials tab list just that
        // case's invoices, same fixed-column trust boundary as the
        // filters above — applied before the admin/non-admin visibility
        // scoping below so it narrows within whatever a user can
        // already see, never expands it.
        if (request()->filled('case_id')) {
            $query->where('case_id', request()->input('case_id'));
        }

        if (auth()->user()->hasRole('admin')) {
            $query->withTrashed()
                ->where(function ($q) {
                    $q->where('workflow_status', 'submitted')
                        ->orWhere('created_by', auth()->user()->id);
                });
        } else {
            $query->where('created_by', auth()->user()->id);
        }

        // UI-28: real, kept-in-sync column (see Invoice::refreshPaymentStatus)
        // — a fixed column name, not client-controlled, same trust boundary
        // as CasesController's lifecycle_status filter. Accepts a
        // comma-separated list (e.g. "unpaid,partially_paid") so a single
        // KPI link can match a multi-status condition exactly.
        if (request()->filled('payment_status')) {
            $statuses = array_filter(explode(',', request()->input('payment_status')));
            $query->whereIn('payment_status', $statuses);
        }

        // UI-28: combined with payment_status above, this is what lets the
        // dashboard's Outstanding Invoices KPI deep-link to an EXACT match
        // regardless of admin status. Without it, an admin's visibility
        // rule below (submitted OR created_by = me) is broader than the
        // dashboard's own query (submitted only) — an admin's own
        // non-submitted drafts could otherwise leak into the linked list
        // but not the KPI total they clicked through from.
        if (request()->filled('workflow_status')) {
            $query->where('workflow_status', request()->input('workflow_status'));
        }

        $invoices = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => \App\Http\Resources\V1\InvoiceResource::make($row)->resolve(),
            25,
            ['invoice_date', 'invoice_due_date'],
            ['invoice_number']
        );

        return $this->response(true, 'success', $invoices, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(\App\Http\Requests\Invoice\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_INVOICES)) {
            abort(403);
        }

        $invoice = app(DraftInvoice::class)->handle($this->invoiceData($request), Auth::user());   // ENG-9

        return $this->response(true, 'success', \App\Http\Resources\V1\InvoiceResource::make($invoice->load(['case', 'client']))->resolve(), 201);
    }

    /**
     * Display the specified resource.
     *
     */
    public function show($id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_INVOICES)) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        if (! auth()->user()->hasRole('admin') && $invoice->created_by !== auth()->user()->id) {
            abort(403);
        }

        $invoice = [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'case' => $invoice->case()->withTrashed()->first()->case_number ?? 'Unknown case',
            'client' => $invoice->client()->withTrashed()->first()->name ?? 'Unknown client',
            'invoice_date' => Carbon::parse($invoice->invoice_date)->format('d/m/Y'),
            'invoice_due_date' => Carbon::parse($invoice->invoice_due_date)->format('d/m/Y'),
            'currency' => $invoice->currency,
            'subtotal' => $invoice->subtotal,
            'tax_total' => $invoice->tax_total,
            'total_amount' => $invoice->total_amount,
            'amount_paid' => $invoice->amount_paid,
            'workflow_status' => $invoice->workflow_status,
            'payment_status' => $invoice->payment_status,
        ];

        return $this->response(true, 'success', $invoice, 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(\App\Http\Requests\Invoice\UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_INVOICES)) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        if (! auth()->user()->hasRole('admin') && $invoice->created_by !== auth()->user()->id) {
            abort(403);
        }

        app(ReviseDraftInvoice::class)->handle($invoice, $this->invoiceData($request), Auth::user());   // ENG-9: drafts only

        return $this->response(true, 'success', \App\Http\Resources\V1\InvoiceResource::make($invoice->fresh(['case', 'client']))->resolve(), 200);
    }

    /** @return array{case_id: mixed, client_id: mixed, invoice_date: string, invoice_due_date: string} the request in the action's terms (dates Y-m-d) */
    private function invoiceData(\Illuminate\Http\Request $request): array
    {
        return [
            'case_id' => $request->case_id,
            'client_id' => $request->client_id,
            'invoice_date' => Carbon::createFromFormat('d/m/Y', $request->invoice_date)->format('Y-m-d'),
            'invoice_due_date' => Carbon::createFromFormat('d/m/Y', $request->invoice_due_date)->format('Y-m-d'),
        ] + array_filter(['currency' => $request->input('currency')]);   // DATA-14
    }

    public function sendToAdmin($id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_INVOICES)) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        if (! auth()->user()->hasRole('admin') && $invoice->created_by !== auth()->user()->id) {
            abort(403);
        }

        app(SubmitInvoice::class)->handle($invoice, Auth::user());   // ENG-9

        return $this->response(true, 'success', \App\Http\Resources\V1\InvoiceResource::make($invoice->fresh(['case', 'client']))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * Admins may void any invoice. A creator may delete their own invoice
     * only while it is still a draft (not yet submitted) — once submitted,
     * only an admin can void it, preserving an audit trail for anything
     * that has left draft state.
     *
     */
    public function destroy($id): JsonResponse
    {
        // the route's InvoicePolicy::delete check (authz:policy) already decided; this keeps the action safe on its own
        $invoice = Invoice::findOrFail($id);
        \Illuminate\Support\Facades\Gate::authorize('delete', $invoice);
        app(VoidInvoice::class)->handle($invoice, Auth::user());   // ENG-9

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * UI-20: bulk delete — mirrors destroy()'s own per-invoice
     * admin-or-own-draft rule rather than a single blanket permission
     * check, since that's the real authorization logic for deleting an
     * invoice at all. Rows the caller isn't allowed to delete are
     * silently skipped (not an error) and reported back as `skipped` so
     * the frontend can tell the person some selected rows weren't
     * removed, rather than the whole batch failing over one invoice
     * they didn't have rights to.
     */
    public function destroyMany(\App\Http\Requests\Invoice\DestroyManyRequest $request): JsonResponse
    {

        $invoices = Invoice::whereIn('id', $request->input('ids'))->get();

        $deleted = [];
        $skipped = [];

        foreach ($invoices as $invoice) {
            if (\Illuminate\Support\Facades\Gate::denies('delete', $invoice)) {   // InvoicePolicy: any invoice with delete-invoice, else own drafts
                $skipped[] = $invoice->id;

                continue;
            }

            app(VoidInvoice::class)->handle($invoice, Auth::user());   // ENG-9
            $deleted[] = $invoice->id;
        }

        return $this->response(true, 'success', ['deleted' => $deleted, 'skipped' => $skipped], 200);
    }

    public function preview($id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_INVOICES)) {
            abort(403);
        }

        $invoice = Invoice::findOrFail($id);

        if (! auth()->user()->hasRole('admin') && $invoice->created_by !== auth()->user()->id) {
            abort(403);
        }

        $items = InvoiceItem::where('invoice_id', $id)->get();
        $total = InvoiceItem::where('invoice_id', $id)
            ->selectRaw('SUM(tax) as total_tax, SUM(total_amount) as totals')
            ->first();

        $items = $items->map(function ($row) {
            return [
                'item' => $row->description,
                'quantity' => $row->quantity,
                'price' => $row->rate,
                'amount' => $row->total_amount,
            ];
        });

        $invoice = [
            'number' => $invoice->invoice_number,
            'date' => $invoice->invoice_date,
            'due_date' => $invoice->invoice_due_date,
            'client' => $invoice->client()->withTrashed()->first()->name ?? 'Unknown client',
            'client_address' => $invoice->client()->withTrashed()->first()->address ?? '',
            'currency' => $invoice->currency,
            'subtotal' => $invoice->subtotal,
            'tax_total' => $invoice->tax_total,
            'total_amount' => $invoice->total_amount,
            'amount_paid' => $invoice->amount_paid,
            'workflow_status' => $invoice->workflow_status,
            'payment_status' => $invoice->payment_status,
            'postShow' => $invoice->workflow_status === 'draft',
        ];

        return $this->response(true, 'success', [$items, $invoice, $total], 200);
    }
}
