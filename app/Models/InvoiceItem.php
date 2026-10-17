<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends DefaultAppModel
{
    use \App\Matters\MatterScoped;

    public static function matterColumn(): string
    {
        return 'invoice_id';
    }

    public static function matterVia(): ?string
    {
        return 'invoices';
    }

    protected $fillable = [
        'utbms_task_code', 'utbms_activity_code', 'utbms_expense_code',   // FUN-13
        'invoice_id',
        'description',
        'quantity',
        'rate',
        'tax',
        'tax_rate_id',          // DATA-14
        'tax_rate_percent',
        'total_amount',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'tax' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'invoice_id');
    }
}
