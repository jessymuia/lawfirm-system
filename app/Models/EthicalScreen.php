<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Matters\WallRegistry;

/** SEC-11: audited (DefaultAppModel). Lifted by setting lifted_at, never deleted. */
class EthicalScreen extends DefaultAppModel
{
    protected $fillable = ['user_id', 'case_id', 'client_id', 'reason', 'lifted_at', 'lifted_by', 'lift_reason', 'updated_by', 'deleted_by'];

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime', 'lifted_at' => 'datetime'];

    protected static function booted(): void
    {
        parent::booted();
        $flush = fn () => app(WallRegistry::class)->flush();
        static::saved($flush);
        static::deleted($flush);
        static::deleting(fn () => throw new \LogicException('Ethical screens are lifted, never deleted.'));
    }

    public function scopeActive($q)
    {
        return $q->whereNull('lifted_at');
    }
}
