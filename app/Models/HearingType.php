<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class HearingType extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'name',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];
}
