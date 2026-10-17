<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Attorney extends DefaultAppModel
{
    protected $fillable = [
        'case_id',
        'advocate_id',
        'role',
        'updated_by',
        'deleted_by',
    ];
}
