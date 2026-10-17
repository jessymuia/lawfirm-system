<?php

namespace App\Actions\Payments;

use App\Actions\ActionException;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9: record money received against an issued invoice: at the counter, or online (FUN-11). The invoice row is locked
 * while the balance is checked, so two payments arriving together can never take it below zero.
 */
class RecordPayment
{
    public const TOLERANCE = 0.01;

    /**
     * @param  array{amount: float|int|string, payment_date: string, method: string, reference_number?: ?string, notes?: ?string}  $data  payment_date as Y-m-d
     * @param  bool  $capAtBalance  online payments (FUN-11): the money is already taken, so it is never refused: what is owed is
     *                              recorded (the caller notes any excess for a manual refund), whatever happened to the invoice since
     */
    public function handle(Invoice $invoice, array $data, ?int $receivedBy, bool $capAtBalance = false): ?Payment
    {
        return DB::transaction(function () use ($invoice, $data, $receivedBy, $capAtBalance) {
            $inv = Invoice::withoutGlobalScopes()->lockForUpdate()->findOrFail($invoice->id);
            if (! $capAtBalance && $inv->workflow_status !== 'submitted') {
                throw new ActionException('Payments can only be recorded against a submitted invoice');
            }
            $outstanding = round((float) $inv->total_amount - (float) $inv->amount_paid, 2);
            $amount = round((float) $data['amount'], 2);
            if ($amount > $outstanding + self::TOLERANCE) {
                if (! $capAtBalance) {
                    throw new ActionException("Payment of {$amount} exceeds the outstanding balance of {$outstanding}");
                }
                $amount = $outstanding;
            }
            if ($amount <= 0) {
                return null;
            }

            return Payment::create([
                'invoice_id' => $inv->id, 'amount' => $amount, 'payment_date' => $data['payment_date'], 'method' => $data['method'],
                'reference_number' => $data['reference_number'] ?? null, 'received_by' => $receivedBy, 'notes' => $data['notes'] ?? null,
            ]);
        });
    }
}
