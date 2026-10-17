<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Hearing;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TimeEntry;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * FUN-4: server-side CSV export of cases. Reuses the same LIST_CASES
     * permission as the normal case list — export is a different output
     * format for data the user could already see, not a way to see more.
     */
    public function cases(): StreamedResponse|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CASES)) {
            abort(403);
        }

        $query = Cases::with(['client', 'attorney', 'court'])->orderBy('id');

        // UI-20: bulk export of just the DataTable's current selection —
        // a fixed, integer-cast id list, not free-form input.
        if (request()->filled('ids')) {
            $ids = array_map('intval', explode(',', request()->input('ids')));
            $query->whereIn('id', $ids);
        }

        $rows = $query->cursor()->map(fn ($row) => [
            $row->id,
            $row->case_number,
            $row->description,
            $row->client->name ?? 'Unknown client',
            $row->attorney->name ?? 'Unassigned',
            $row->court?->displayName() ?? 'Unknown court',
            $row->case_type,
            $row->lifecycle_status,
            $row->start_date,
            $row->end_date,
        ]);

        return $this->streamCsv('cases-'.now()->format('Y-m-d').'.csv', [
            'ID', 'Case Number', 'Description', 'Client', 'Attorney', 'Court', 'Type', 'Status', 'Start Date', 'End Date',
        ], $rows);
    }

    public function expenses(): StreamedResponse|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_EXPENSES)) {
            abort(403);
        }

        $query = Expense::with(['case', 'categoryName', 'user'])->orderBy('id');

        // UI-20: bulk export of just the DataTable's current selection.
        if (request()->filled('ids')) {
            $ids = array_map('intval', explode(',', request()->input('ids')));
            $query->whereIn('id', $ids);
        }

        $rows = $query->cursor()->map(fn ($row) => [
            $row->id,
            $row->case->case_number ?? 'Unknown case',
            Carbon::parse($row->date)->format('Y-m-d'),
            $row->amount,
            $row->categoryName->name ?? '',
            $row->description,
            $row->vendor,
            $row->payment_method,
            $row->user->name ?? 'Unknown user',
        ]);

        return $this->streamCsv('expenses-'.now()->format('Y-m-d').'.csv', [
            'ID', 'Case', 'Date', 'Amount', 'Category', 'Description', 'Vendor', 'Payment Method', 'Recorded By',
        ], $rows);
    }

    /**
     * Invoices are creator/admin-scoped, same as InvoiceController::index
     * — the export respects the same visibility rules.
     */
    public function invoices(): StreamedResponse|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_INVOICES)) {
            abort(403);
        }

        $query = Invoice::with(['case', 'client'])->orderBy('id');

        if (! auth()->user()->hasRole('admin')) {
            $query->where('created_by', auth()->user()->id);
        }

        // UI-20: bulk export of just the DataTable's current selection —
        // intersected with the visibility scoping above, never expanding it.
        if (request()->filled('ids')) {
            $ids = array_map('intval', explode(',', request()->input('ids')));
            $query->whereIn('id', $ids);
        }

        $rows = $query->cursor()->map(fn ($row) => [
            $row->id,
            $row->invoice_number,
            $row->case->case_number ?? 'Unknown case',
            $row->client->name ?? 'Unknown client',
            $row->currency,
            $row->subtotal,
            $row->tax_total,
            $row->total_amount,
            $row->amount_paid,
            $row->workflow_status,
            $row->payment_status,
            $row->invoice_date,
            $row->invoice_due_date,
        ]);

        return $this->streamCsv('invoices-'.now()->format('Y-m-d').'.csv', [
            'ID', 'Invoice Number', 'Case', 'Client', 'Currency', 'Subtotal', 'Tax', 'Total', 'Paid', 'Workflow Status', 'Payment Status', 'Invoice Date', 'Due Date',
        ], $rows);
    }

    /**
     * PDF export of a single invoice — a server-rendered version of what
     * the client-side jsPDF preview currently produces, but generated from
     * trusted server data rather than whatever the browser has in memory.
     */
    public function invoicePdf($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_INVOICES)) {
            abort(403);
        }

        $invoice = Invoice::with(['case', 'client', 'items'])->findOrFail($id);

        if (! auth()->user()->hasRole('admin') && $invoice->created_by !== auth()->user()->id) {
            abort(403);
        }

        // dompdf writes its font cache here; without the directory the text is silently dropped and the PDF comes out blank
        \Illuminate\Support\Facades\File::ensureDirectoryExists(storage_path('fonts'));

        $pdf = Pdf::loadView('exports.invoice', ['invoice' => $invoice]);
        $bytes = $pdf->output();

        // a real PDF starts with %PDF and is never a few hundred bytes; say so rather than hand over an empty file
        if (strncmp($bytes, '%PDF', 4) !== 0 || strlen($bytes) < 1500) {
            \Illuminate\Support\Facades\Log::error('invoice.pdf empty output', ['invoice' => $invoice->id, 'bytes' => strlen($bytes)]);

            return response()->json(['success' => false, 'message' => 'The PDF could not be generated. Please try again or contact support.', 'data' => null], 500);
        }

        // drop anything echoed earlier in the request so it cannot be written into the file
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Length' => (string) strlen($bytes),
            'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($invoice->invoice_number ?: 'invoice')).'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * Full-firm data export: one CSV per module, bundled into a single
     * zip. Deliberately admin-only (EXPORT_FIRM_DATA) — this is the one
     * export that ignores per-record scoping (e.g. every invoice
     * regardless of creator) because its purpose is a complete backup/
     * extraction of the firm's data, not a personal work view.
     */
    public function fullFirmExport(): StreamedResponse|JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::EXPORT_FIRM_DATA)) {
            abort(403);
        }
        // SEC-10: a complete extraction would hand a restricted user matters they cannot otherwise see.
        if (\App\Matters\MatterAccess::subject()) {
            abort(403, 'The full-firm export requires access to all matters.');
        }

        // ENG-21: prefer POST /export/firm-data (queued, emailed link). This synchronous form stays for existing callers.
        $zipPath = (new \App\Exports\FirmExportBuilder)->build();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }

    // ---- ENG-21: queued firm-wide export --------------------------------------------------------------------------

    /** Queue a firm-wide export. 202 with the request; poll firmExportStatus for the download link. */
    public function requestFirmExport(): JsonResponse
    {
        if (\App\Matters\MatterAccess::subject()) {
            abort(403, 'The full-firm export requires access to all matters.');
        }
        $export = \App\Models\FirmExport::create(['requested_by' => Auth::id(), 'status' => 'queued']);
        \App\Jobs\BuildFirmExport::dispatch($export->id);

        return response()->json(['success' => true, 'message' => 'Export queued. You will get an email when it is ready.', 'data' => $this->exportView($export->fresh())], 202);
    }

    public function firmExportStatus(int $id): JsonResponse
    {
        $export = \App\Models\FirmExport::findOrFail($id);
        abort_unless($export->requested_by === Auth::id(), 404);   // your own exports only

        return response()->json(['success' => true, 'data' => $this->exportView($export)]);
    }

    /** Signed, expiring URL (route middleware `signed`) AND the requester's own session: a leaked link alone opens nothing. */
    public function downloadFirmExport(int $id)
    {
        $export = \App\Models\FirmExport::findOrFail($id);
        abort_unless($export->requested_by === Auth::id(), 404);
        abort_unless($export->isDownloadable(), 410, 'This export has expired. Request a new one.');

        return \Illuminate\Support\Facades\Storage::disk('local')->download((string) $export->path, 'firm-export-'.$export->ready_at?->format('Y-m-d').'.zip');
    }

    private function exportView(\App\Models\FirmExport $e): array
    {
        return [
            'id' => $e->id, 'status' => $e->isDownloadable() || $e->status !== 'ready' ? $e->status : 'expired',
            'size' => $e->size, 'requested_at' => $e->created_at?->toIso8601String(), 'ready_at' => $e->ready_at?->toIso8601String(),
            'expires_at' => $e->expires_at?->toIso8601String(), 'error' => $e->status === 'failed' ? 'The export could not be built. Try again or contact support.' : null,
            'download_url' => $e->isDownloadable()
                ? \Illuminate\Support\Facades\URL::temporarySignedRoute('exports.firm.download', now()->addMinutes((int) config('exports.signed_url_minutes', 15)), ['id' => $e->id], absolute: false)
                : null,
        ];
    }
}
