<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Invoice extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_id',
        'invoice_date',
        'invoice_due_date',
        'client_id'
    ];
}
