<?php

namespace App\Actions\Invoices;

use App\Models\Invoice;
use App\Models\User;
use App\Money\Money;

/** ENG-9: start a draft invoice for a matter (its number comes from the firm's sequence, DATA-8). */
class DraftInvoice
{
    /** @param array{case_id: int, client_id: int, invoice_date: string, invoice_due_date: string, currency?: string} $data dates as Y-m-d */
    public function handle(array $data, ?User $actor): Invoice
    {
        $invoice = new Invoice;
        $invoice->fill([
            'case_id' => $data['case_id'], 'client_id' => $data['client_id'], 'invoice_date' => $data['invoice_date'],
            'invoice_due_date' => $data['invoice_due_date'], 'workflow_status' => 'draft',
            // DATA-14: the firm's base currency unless the invoice is in another
            'currency' => strtoupper($data['currency'] ?? app(Money::class)->baseCurrency()),
        ]);
        $actor && $invoice->forceFill(['created_by' => $actor->getKey()]);
        $invoice->save();

        return $invoice;
    }
}
