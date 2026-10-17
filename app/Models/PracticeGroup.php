<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FUN-18: a practice group (Litigation, Conveyancing…) inside the firm, optionally with a head. */
class PracticeGroup extends DefaultAppModel
{
    protected $fillable = ['name', 'code', 'description', 'head_user_id'];

    /** @return BelongsTo<User, $this> */
    public function head(): BelongsTo
    {
        return $this->belongsTo(User::class, 'head_user_id');
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return HasMany<Cases, $this> */
    public function matters(): HasMany
    {
        return $this->hasMany(Cases::class);
    }
}
