<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Cases extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_number',
        'description',
        'client_id',
        'assigned_to',
        'start_date',
        'end_date',
        'case_type',
        'police_station',
        'court_id',
        'opposing_party',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];
}
