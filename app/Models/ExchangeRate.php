<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** DATA-14: one unit of `currency` was worth `rate` units of `base_currency` from `effective_on`. */
class ExchangeRate extends Model
{
    protected $fillable = ['currency', 'base_currency', 'rate', 'effective_on', 'source', 'created_by'];

    protected $casts = ['rate' => 'decimal:8', 'effective_on' => 'date:Y-m-d'];
}
