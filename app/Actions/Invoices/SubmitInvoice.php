<?php

namespace App\Actions\Invoices;

use App\Actions\ActionException;
use App\Actions\Concerns\Stamps;
use App\Models\Invoice;
use App\Models\User;
use App\Money\MissingExchangeRate;
use App\Money\Money;

/**
 * ENG-9: issue a draft. From here its lines and totals are frozen (DATA-10) and payments can be recorded against it.
 * DATA-14: issuing also fixes its exchange rate to the firm's base currency.
 */
class SubmitInvoice
{
    use Stamps;

    public function handle(Invoice $invoice, ?User $actor): Invoice
    {
        if ($invoice->workflow_status !== 'draft') {
            throw new ActionException('Invoice has already been submitted');
        }
        try {
            // DATA-14: the exchange rate and base-currency totals are fixed now, once, with the issue
            $invoice->forceFill(app(Money::class)->fixFor($invoice));
        } catch (MissingExchangeRate $e) {
            throw new ActionException($e->getMessage(), 422, 'missing_exchange_rate');
        }
        $invoice->workflow_status = 'submitted';
        $this->stamp($invoice, $actor)->save();

        return $invoice;
    }
}
