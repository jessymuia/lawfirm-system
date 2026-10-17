<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends DefaultAppModel
{
    use \App\Matters\MatterScoped;

    protected $fillable = [
        'utbms_expense_code',   // FUN-13
        'case_id',
        'date',
        'amount',
        'category',
        'description',
        'vendor',
        'payment_method',
        'invoice_number',
        'user_id',
        'updated_by',
        'deleted_by',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'amount' => 'decimal:2',
    ];

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function categoryName(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'category');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    /** @return BelongsTo<Cases, $this> */
    public function case(): BelongsTo
    {
        return $this->belongsTo(Cases::class, 'case_id')->withTrashed();
    }
}
