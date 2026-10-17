<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** DATA-14: one of the firm's tax rules (a percentage, e.g. VAT 16%), archived rather than deleted. */
class TaxRate extends Model
{
    protected $fillable = ['name', 'rate', 'jurisdiction', 'is_default', 'archived_at', 'created_by'];

    protected $casts = ['rate' => 'decimal:4', 'is_default' => 'bool', 'is_system' => 'bool', 'archived_at' => 'datetime'];
}
