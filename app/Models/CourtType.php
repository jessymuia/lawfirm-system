<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourtType extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'name',
        'created_by',
        'status',
        'updated_by',
        'deleted_by',
    ];
}
