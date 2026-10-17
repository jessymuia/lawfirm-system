<?php

namespace App\Actions\Time;

use App\Actions\ActionException;
use App\Actions\Invoices\DraftInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: turn a matter's unbilled billable time into a draft invoice: one line per entry, each entry marked billed and
 * tied to its line so it can never be billed twice. The entries are locked while this happens.
 */
class BillTime
{
    public function __construct(private DraftInvoice $draft) {}

    public function handle(int $caseId, int $clientId, ?User $actor, int $dueDays = 30): Invoice
    {
        return DB::transaction(function () use ($caseId, $clientId, $actor, $dueDays) {
            $entries = TimeEntry::where('case_id', $caseId)->where('billable', true)->where('billed', false)->lockForUpdate()->get();
            if ($entries->isEmpty()) {
                throw new ActionException('No unbilled billable time entries for this case');
            }
            $invoice = $this->draft->handle(['case_id' => $caseId, 'client_id' => $clientId, 'invoice_date' => now()->toDateString(),
                'invoice_due_date' => now()->addDays($dueDays)->toDateString()], $actor);
            foreach ($entries as $entry) {
                $item = InvoiceItem::create([
                    'invoice_id' => $invoice->id, 'description' => $entry->description.' ('.$entry->hours.' hrs)', 'quantity' => $entry->hours,
                    'rate' => $entry->hourly_rate, 'tax' => 0, 'total_amount' => $entry->amount,
                    'utbms_task_code' => $entry->utbms_task_code, 'utbms_activity_code' => $entry->utbms_activity_code,   // FUN-13: the codes travel to the bill
                ]);
                $entry->update(['billed' => true, 'invoice_item_id' => $item->id]);
            }
            $invoice->refreshTotals();

            return $invoice;
        });
    }
}
