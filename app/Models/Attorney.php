<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Attorney extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_id',
        'advocate_id',
        'role',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];
}
