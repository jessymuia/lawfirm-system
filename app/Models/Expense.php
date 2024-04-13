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
        'user_id'
    ];
}
