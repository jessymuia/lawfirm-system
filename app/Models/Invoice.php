<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;

class Invoice extends DefaultAppModel
{
    protected $fillable = [
        'id',
        'case_id',
        'invoice_date',
        'invoice_due_date',
        'client_id',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function case(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Cases::class,'case_id');
    }

    public function client(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Client::class,'client_id');
    }
}
