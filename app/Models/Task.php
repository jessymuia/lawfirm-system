<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Task extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'description',
        'title',
        'assigned_to',
        'due_date',
        'priority',
        'status'
    ];
}
