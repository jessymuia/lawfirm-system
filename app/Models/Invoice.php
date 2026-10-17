<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Invoice extends DefaultAppModel
{
    use \App\Models\Concerns\ConstrainedToEnums;

    /** DATA-12: columns that only ever hold one of their enum's values */
    public const ENUM_COLUMNS = ['workflow_status' => \App\Enums\InvoiceWorkflow::class, 'payment_status' => \App\Enums\PaymentStatus::class];

    use \App\Matters\MatterScoped;
    use \App\Models\Concerns\HasPublicId;

    use \App\Models\Concerns\InsertsInsideTransaction;

    protected $fillable = [
        'invoice_number',
        'case_id',
        'invoice_date',
        'invoice_due_date',
        'client_id',
        'currency',
        'workflow_status',
        'payment_status',
        'subtotal',
        'tax_total',
        'total_amount',
        'amount_paid',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'fx_rate' => 'decimal:8',              // DATA-14: fixed when issued
        'base_subtotal' => 'decimal:2',
        'base_total_amount' => 'decimal:2',
    ];

    /** DATA-14: an amount of this invoice in the firm's base currency, at the rate it fixed; null when it has none. */
    public function inBase(float $amount): ?float
    {
        $base = app(\App\Money\Money::class)->baseCurrency();
        $rate = $this->fx_rate !== null ? (float) $this->fx_rate : ((! $this->currency || strtoupper($this->currency) === $base) ? 1.0 : null);

        return $rate === null ? null : round($amount * $rate, 2);
    }

    /**
     * Bug fix (found via a real end-to-end run): this method completely
     * shadowed DefaultAppModel::booted() instead of extending it, so the
     * parent's `creating` listener — the one that stamps created_by from
     * the authenticated user — never ran for any Invoice. Every invoice
     * ever created had created_by = NULL, which silently broke every
     * ownership check that depends on it: a non-admin's own invoices
     * list (InvoiceController::index's created_by scoping matched
     * nothing), viewing a single invoice (show()'s admin-or-owner
     * check), and both single and bulk delete's admin-or-own-draft
     * check — all correctly implemented, all just comparing against a
     * column that was never actually populated.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Invoice $invoice) {
            if (empty($invoice->invoice_number)) {
                $invoice->invoice_number = self::nextInvoiceNumber();
            }
        });
    }

    /**
     * DATA-8: numbers come from the locked sequence service, not MAX(id)+1
     * (which collided under concurrent creates), and are drawn inside the
     * same transaction as the INSERT (InsertsInsideTransaction), so the series
     * has no gaps. First use continues from the highest existing INV- number.
     * Per-year format (INV-2026-000123): config('invoicing.number_per_year').
     */
    public static function nextInvoiceNumber(): string
    {
        return app(\App\Services\SequenceService::class)->nextFormatted('invoice', 'INV', 'invoices', 'invoice_number');
    }

    /**
     * Recalculate and persist the cached totals from this invoice's items.
     * Call after any invoice_items create/update/delete.
     */
    public function refreshTotals(): void
    {
        $totals = InvoiceItem::where('invoice_id', $this->id)
            ->selectRaw('COALESCE(SUM(rate * quantity), 0) as subtotal, COALESCE(SUM(tax), 0) as tax_total, COALESCE(SUM(total_amount), 0) as total_amount')
            ->toBase()->first();   // plain aggregates, not an InvoiceItem

        $this->update([
            'subtotal' => $totals->subtotal,
            'tax_total' => $totals->tax_total,
            'total_amount' => $totals->total_amount,
        ]);

        $this->refreshPaymentStatus();
    }

    public function refreshPaymentStatus(): void
    {
        $paymentStatus = \App\Enums\PaymentStatus::for((float) $this->total_amount, (float) $this->amount_paid)->value;   // DATA-12

        if ($paymentStatus !== $this->payment_status) {
            $this->update(['payment_status' => $paymentStatus]);
        }
    }

    /**
     * Recompute amount_paid from this invoice's non-voided payments, then
     * refresh the derived payment_status. Called whenever a Payment is
     * created or removed (see Payment::booted()).
     */
    public function syncAmountPaid(): void
    {
        $total = $this->payments()->sum('amount');

        if ((float) $total !== (float) $this->amount_paid) {
            $this->update(['amount_paid' => $total]);
        }

        $this->refreshPaymentStatus();
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'invoice_id');
    }

    /** @return BelongsTo<Cases, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Cases::class, 'case_id');
    }

    /** @return BelongsTo<Client, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'invoice_id');
    }
}
