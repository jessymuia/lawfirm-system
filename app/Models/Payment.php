<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Enums\PaymentMethod;
use App\Matters\MatterScoped;
use App\Models\Concerns\ConstrainedToEnums;
use App\Models\Concerns\HasPublicId;
use App\Models\Concerns\InsertsInsideTransaction;
use App\Services\SequenceService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends DefaultAppModel
{
    use ConstrainedToEnums;

    /** DATA-12: columns that only ever hold one of their enum's values */
    public const ENUM_COLUMNS = ['method' => PaymentMethod::class];

    use HasPublicId;
    use MatterScoped;

    public static function matterColumn(): string
    {
        return 'invoice_id';
    }

    public static function matterVia(): ?string
    {
        return 'invoices';
    }

    use InsertsInsideTransaction;

    protected $fillable = [
        'invoice_id',
        'receipt_number',
        'amount',
        'payment_date',
        'method',
        'reference_number',
        'received_by',
        'notes',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    /**
     * Bug fix (found via a real end-to-end run — see Invoice::booted()'s
     * identical fix): this shadowed DefaultAppModel::booted() instead of
     * extending it, so created_by was never stamped on any Payment.
     * Nothing currently reads Payment.created_by, so this wasn't yet
     * causing a visible failure the way Invoice's version was — fixed
     * for correctness before something does depend on it.
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (Payment $payment) {
            if (empty($payment->receipt_number)) {
                $payment->receipt_number = self::nextReceiptNumber();
            }
        });

        // Keep the parent invoice's cached amount_paid / payment_status in
        // sync whenever a payment is recorded or removed.
        static::created(fn (Payment $payment) => $payment->invoice?->syncAmountPaid());
        static::deleted(fn (Payment $payment) => $payment->invoice?->syncAmountPaid());
    }

    /** DATA-8: same locked, gap-free, per-tenant sequence as invoices (RCT-000123). */
    public static function nextReceiptNumber(): string
    {
        return app(SequenceService::class)->nextFormatted('receipt', 'RCT', 'payments', 'receipt_number');
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by')->withTrashed();
    }
}
