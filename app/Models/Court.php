<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Court extends DefaultAppModel
{
    use HasFactory;
    protected $fillable = [
        'id',
        'name',
        'type'
    ];
}
