<?php

namespace App\Models;

use App\AppUtils\DefaultAppModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** FUN-18: one of the firm's offices (inside the firm; never a separate tenant). */
class Office extends DefaultAppModel
{
    protected $fillable = ['name', 'code', 'city', 'address', 'phone', 'email', 'is_head_office'];

    protected $casts = ['is_head_office' => 'boolean'];

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
