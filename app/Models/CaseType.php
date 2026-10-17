<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * DATA-12: one of the firm's matter types (App\Matters\CaseTypes).
 *
 * @property int $id
 * @property string $name
 * @property Carbon|null $archived_at
 */
class CaseType extends Model
{
    protected $fillable = ['name', 'archived_at', 'created_by'];

    protected $casts = ['archived_at' => 'datetime'];
}
