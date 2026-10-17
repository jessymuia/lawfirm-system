<?php

namespace App\Http\Controllers;

use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\RemovePayment;
use App\Constants\ModulePermissions;
use App\Http\Requests\Payment\StoreRequest;
use App\Http\Resources\V1\PaymentResource;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class PaymentController extends Controller
{
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_PAYMENTS)) {
            abort(403);
        }

        $query = Payment::with(['invoice', 'receivedBy'])->orderBy('id', 'desc');

        if (request()->has('invoice_id')) {
            $query->where('invoice_id', request()->input('invoice_id'));
        }

        $payments = $this->paginatedOrFull(request(), $query, fn ($row) => PaymentResource::make($row)->resolve());

        return $this->response(true, 'success', $payments, 200);
    }

    public function store(StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_PAYMENTS)) {
            abort(403);
        }

        // ENG-9: issued invoices only, never above the balance (the invoice is locked while that is checked)
        $payment = app(RecordPayment::class)->handle(Invoice::findOrFail($request->invoice_id), [
            'amount' => $request->amount,
            'payment_date' => Carbon::createFromFormat('d/m/Y', $request->payment_date)->format('Y-m-d'),
            'method' => $request->method,
            'reference_number' => $request->reference_number,
            'notes' => $request->notes,
        ], (int) auth()->user()->id);

        return $this->response(true, 'success', ['receipt_number' => $payment->receipt_number], 200);
    }

    public function show($id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_PAYMENTS)) {
            abort(403);
        }

        $payment = Payment::with(['invoice.client', 'receivedBy'])->findOrFail($id);

        return $this->response(true, 'success', [
            'receipt_number' => $payment->receipt_number,
            'invoice_number' => $payment->invoice?->invoice_number,
            'client' => $payment->invoice?->client->name ?? 'Unknown client',
            'amount' => $payment->amount,
            'payment_date' => Carbon::parse($payment->payment_date)->format('d/m/Y'),
            'method' => $payment->method,
            'reference_number' => $payment->reference_number,
            'received_by' => $payment->receivedBy->name ?? 'Unknown user',
        ], 200);
    }

    /**
     * Void a payment (soft-delete). The invoice's amount_paid/payment_status
     * are kept in sync automatically via Payment::booted() -> deleted hook.
     */
    public function destroy($id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_PAYMENTS)) {
            abort(403);
        }

        app(RemovePayment::class)->handle(Payment::findOrFail($id), Auth::user());   // ENG-9

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }
}
