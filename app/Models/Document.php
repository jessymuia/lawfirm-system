<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Document extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_id',
        'title',
        'filename',
        'filepath',
        'full_path',
        'mimetype',
        'filesize',
        'extension'
    ];
}
