<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class DocumentAccess extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'document_id',
        'accessed_by',
        'accessed_date',
        'action',
        'ip_address',
        'outcome',
        'device_info'
    ];
}
