<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class ExpenseCategory extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'name',
        'created_by',
        'updated_by'
    ];
}
