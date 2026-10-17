<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use App\Matters\ClientWallScope;
use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Client extends DefaultAppModel
{
    use HasPublicId;

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope(new ClientWallScope);
    }

    protected $fillable = [
        'ebilling_client_id',   // FUN-13
        'name',
        'phone_number',
        'extra_phone_number',
        'address',
        'advocate_id',
        'updated_by',
        'deleted_by',
    ];

    /** @return BelongsTo<User, $this> */
    public function advocate(): BelongsTo
    {
        return $this->belongsTo(User::class, 'advocate_id')->withTrashed();
    }
}
