<?php

namespace App\Actions\Invoices;

use App\Actions\ActionException;
use App\Actions\Concerns\Stamps;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TaxRate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: add, change and remove a draft invoice's lines. A line's total is always worked out here (quantity x rate +
 * tax), never taken from the caller, and the invoice's totals follow every change. Lines of an issued invoice are
 * frozen (DATA-10; the database refuses too, this gives the reason).
 */
class ChangeInvoiceLines
{
    use Stamps;

    public const CODES = ['utbms_task_code', 'utbms_activity_code', 'utbms_expense_code'];

    /** @param array{description: string, quantity: float|int|string, rate: float|int|string, tax?: float|int|string, tax_rate_id?: ?int} $data plus optional UTBMS codes */
    public function add(Invoice $invoice, array $data, ?User $actor): InvoiceItem
    {
        $this->draft($invoice);

        return DB::transaction(function () use ($invoice, $data, $actor) {
            $item = new InvoiceItem;
            $item->fill(['invoice_id' => $invoice->id] + $this->values($data) + array_intersect_key($data, array_flip(self::CODES)));
            $actor && $item->forceFill(['created_by' => $actor->getKey()]);
            $item->save();
            $invoice->refreshTotals();

            return $item;
        });
    }

    /** @param array<string, mixed> $data the line's values; UTBMS codes only when given */
    public function update(InvoiceItem $item, array $data, ?User $actor): InvoiceItem
    {
        $invoice = $this->invoiceOf($item);
        $this->draft($invoice);

        return DB::transaction(function () use ($item, $invoice, $data, $actor) {
            $item->fill($this->values($data) + array_intersect_key($data, array_flip(self::CODES)));
            $this->stamp($item, $actor)->save();
            $invoice->refreshTotals();

            return $item;
        });
    }

    public function remove(InvoiceItem $item, ?User $actor): Invoice
    {
        $invoice = $this->invoiceOf($item);
        $this->draft($invoice);
        DB::transaction(function () use ($item, $invoice, $actor) {
            $this->softDelete($item, $actor);
            $invoice->refreshTotals();
        });

        return $invoice;
    }

    /**
     * DATA-14: with a tax rate the line's tax is worked out from it (and the percentage kept, so the line still says what
     * it was charged under if the rate later changes); without one the tax is the amount given, as before.
     *
     * @return array{description: string, quantity: mixed, rate: mixed, tax: mixed, total_amount: float, tax_rate_id: ?int, tax_rate_percent: mixed}
     */
    private function values(array $data): array
    {
        $net = (float) $data['quantity'] * (float) $data['rate'];
        $taxRate = empty($data['tax_rate_id']) ? null : TaxRate::whereNull('archived_at')->find($data['tax_rate_id']);
        if (! empty($data['tax_rate_id']) && ! $taxRate) {
            throw new ActionException('That tax rate is archived or does not exist.', 422, 'tax_rate_unavailable');
        }
        $tax = $taxRate ? round($net * (float) $taxRate->rate / 100, 2) : (float) ($data['tax'] ?? 0);

        return ['description' => $data['description'], 'quantity' => $data['quantity'], 'rate' => $data['rate'], 'tax' => $tax,
            'tax_rate_id' => $taxRate?->id, 'tax_rate_percent' => $taxRate?->rate, 'total_amount' => round($net + $tax, 2)];
    }

    private function invoiceOf(InvoiceItem $item): Invoice
    {
        // judged by the line's real invoice, never an id a caller sent
        return Invoice::withoutGlobalScopes()->findOrFail($item->invoice_id);
    }

    private function draft(Invoice $invoice): void
    {
        if ($invoice->workflow_status !== 'draft') {
            throw new ActionException('Items of a submitted invoice cannot be changed.');
        }
    }
}
