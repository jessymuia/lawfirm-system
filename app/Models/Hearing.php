<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Hearing extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_id',
        'court_id',
        'hearing_date',
        'hearing_type',
        'notes',
        'outcome'
    ];

    public function hearingTypeName(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(HearingType::class,'hearing_type');
    }
}
