<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Client extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'name',
        'phone_number',
        'extra_phone_number',
        'address',
        'advocate_id'
    ];
}
