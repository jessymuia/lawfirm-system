<?php

namespace App\Exports;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Hearing;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Tenancy\TenantContext;

/**
 * The firm-wide export: one CSV per module, zipped. Used by the queued export (ENG-21) and by the legacy synchronous
 * endpoint. It ignores per-record scoping on purpose (it is the firm's complete extraction); callers must have checked
 * EXPORT_FIRM_DATA and unrestricted matter access first.
 */
class FirmExportBuilder
{
    /** @return string absolute path of the zip, in this firm's scratch space; the caller owns (moves or deletes) it */
    public function build(): string
    {
        // TEN-10: scratch space is per firm AND unguessable, never a name two requests (or two firms) could share.
        $scratch = storage_path('app/tmp-exports/'.app(TenantContext::class)->key());
        $tmpDir = $scratch.'/'.bin2hex(random_bytes(8));
        mkdir($tmpDir, 0755, true);

        $exports = [
            'clients.csv' => [
                'headers' => ['ID', 'Name', 'Phone', 'Address', 'Advocate'],
                'rows' => Client::with('advocate')->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->name, $r->phone_number, $r->address, $r->advocate->name ?? '',
                ]),
            ],
            'cases.csv' => [
                'headers' => ['ID', 'Case Number', 'Description', 'Client', 'Attorney', 'Status'],
                'rows' => Cases::with(['client', 'attorney'])->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->case_number, $r->description, $r->client->name ?? '', $r->attorney->name ?? '', $r->lifecycle_status,
                ]),
            ],
            'hearings.csv' => [
                'headers' => ['ID', 'Case', 'Court', 'Date', 'Outcome'],
                'rows' => Hearing::with(['case', 'court'])->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->case->case_number ?? '', $r->court?->displayName() ?? '', $r->hearing_date, $r->outcome,
                ]),
            ],
            'invoices.csv' => [
                'headers' => ['ID', 'Invoice Number', 'Case', 'Client', 'Total', 'Paid', 'Status'],
                'rows' => Invoice::with(['case', 'client'])->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->invoice_number, $r->case->case_number ?? '', $r->client->name ?? '', $r->total_amount, $r->amount_paid, $r->workflow_status,
                ]),
            ],
            'payments.csv' => [
                'headers' => ['ID', 'Receipt Number', 'Invoice', 'Amount', 'Date', 'Method'],
                'rows' => Payment::with('invoice')->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->receipt_number, $r->invoice->invoice_number ?? '', $r->amount, $r->payment_date, $r->method,
                ]),
            ],
            'expenses.csv' => [
                'headers' => ['ID', 'Case', 'Amount', 'Category', 'Date'],
                'rows' => Expense::with(['case', 'categoryName'])->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->case->case_number ?? '', $r->amount, $r->categoryName->name ?? '', $r->date,
                ]),
            ],
            'time_entries.csv' => [
                'headers' => ['ID', 'Case', 'User', 'Hours', 'Rate', 'Amount', 'Billed'],
                'rows' => TimeEntry::with(['case', 'user'])->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->case->case_number ?? '', $r->user->name ?? '', $r->hours, $r->hourly_rate, $r->amount, $r->billed ? 'yes' : 'no',
                ]),
            ],
            'tasks.csv' => [
                'headers' => ['ID', 'Title', 'Assigned To', 'Due Date', 'Status'],
                'rows' => Task::with('assignee')->orderBy('id')->cursor()->map(fn ($r) => [
                    $r->id, $r->title, $r->assignee->name ?? '', $r->due_date, $r->task_status,
                ]),
            ],
        ];

        foreach ($exports as $filename => $export) {
            $handle = fopen($tmpDir.'/'.$filename, 'w');
            fputcsv($handle, $export['headers']);
            foreach ($export['rows'] as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }

        $zipPath = $scratch.'/firm-export-'.now()->format('Y-m-d-His').'-'.bin2hex(random_bytes(8)).'.zip';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE);
        foreach (array_keys($exports) as $filename) {
            $zip->addFile($tmpDir.'/'.$filename, $filename);
        }
        $zip->close();

        // Clean up the loose per-module CSVs now that they're zipped;
        // only the zip itself needs to survive to be streamed back.
        array_map('unlink', glob($tmpDir.'/*'));
        @rmdir($tmpDir);

        return $zipPath;
    }
}
