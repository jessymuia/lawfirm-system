<?php

namespace App\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** TEN-5: a person's single login, shared across every firm they belong to. */
class Identity extends Model
{
    protected $fillable = ['email', 'password', 'status'];

    protected $hidden = ['password'];

    public function getConnectionName()
    {
        return config('tenancy.control_connection');
    }

    /** @return HasMany<TenantMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
