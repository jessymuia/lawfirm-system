<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Expense extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_id',
        'date',
        'amount',
        'category',
        'description',
        'vendor',
        'payment_method',
        'invoice_number',
        'user_id',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function categoryName(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
       return $this->belongsTo(ExpenseCategory::class, 'category');
    }
}
