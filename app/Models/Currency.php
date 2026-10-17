<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** DATA-14: a currency the firm can invoice in (ISO 4217 code, minor units). */
class Currency extends Model
{
    protected $fillable = ['code', 'name', 'minor_units', 'symbol', 'enabled'];

    protected $casts = ['enabled' => 'bool', 'minor_units' => 'int', 'is_system' => 'bool'];
}
