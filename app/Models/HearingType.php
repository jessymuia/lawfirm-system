<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class HearingType extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'name',
        'created_by'
    ];
}
