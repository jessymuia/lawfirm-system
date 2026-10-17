<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class ExpenseCategory extends DefaultAppModel
{
    protected $fillable = [
        'name',
        'updated_by',
    ];
}
