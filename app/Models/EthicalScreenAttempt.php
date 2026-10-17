<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** SEC-11: append-only record of attempts to reach walled material. No update or delete, in code or through any route. */
class EthicalScreenAttempt extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Screen attempts are append-only.'));
        static::deleting(fn () => throw new \LogicException('Screen attempts are append-only.'));
    }
}
