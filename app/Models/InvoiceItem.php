<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class InvoiceItem extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'invoice_id',
        'description',
        'quantity',
        'rate',
        'tax',
        'total_amount',
        'created_by',
        'updated_by',
        'deleted_by'
    ];
}
