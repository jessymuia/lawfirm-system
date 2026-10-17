<?php

namespace App\Http\Controllers;

use App\Actions\Invoices\ChangeInvoiceLines;
use App\Constants\ModulePermissions;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class InvoiceItemController extends Controller
{

    /**
     * Store a newly created resource in storage.
     */
    public function store(\App\Http\Requests\InvoiceItem\StoreRequest $request): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_INVOICES)) {
            abort(403);
        }

        // ENG-9: drafts only; the line's total is worked out by the action (quantity x rate + tax), never taken from the request
        app(ChangeInvoiceLines::class)->add(Invoice::findOrFail($request->invoice_id), $request->only(['description', 'quantity', 'rate', 'tax', 'tax_rate_id'])
            + $request->only(array_values(array_filter(ChangeInvoiceLines::CODES, fn ($f) => $request->filled($f)))), Auth::user());

        $items = InvoiceItem::where('invoice_id', $request->invoice_id)->orderBy('id', 'desc')->get();

        return $this->response(true, 'success', $items, 200);
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

        $items = InvoiceItem::where('invoice_id', $id)->get();

        return $this->response(true, 'success', $items, 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(\App\Http\Requests\InvoiceItem\UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_INVOICES)) {
            abort(403);
        }

        $item = app(ChangeInvoiceLines::class)->update(InvoiceItem::findOrFail($id),   // ENG-9: drafts only, judged by the line's real invoice
            $request->only(['description', 'quantity', 'rate', 'tax', 'tax_rate_id']) + $request->only(array_values(array_filter(ChangeInvoiceLines::CODES, fn ($f) => $request->exists($f)))),
            Auth::user());

        $items = InvoiceItem::where('invoice_id', $item->invoice_id)->orderBy('id', 'desc')->get();

        return $this->response(true, 'success', $items, 200);

    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy($id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_INVOICES)) {
            abort(403);
        }

        $invoiceId = (int) app(ChangeInvoiceLines::class)->remove(InvoiceItem::findOrFail($id), Auth::user())->id;   // ENG-9

        $items = InvoiceItem::where('invoice_id', $invoiceId)->orderBy('id', 'desc')->get();

        return $this->response(true, 'success', $items, 200);
    }

}
