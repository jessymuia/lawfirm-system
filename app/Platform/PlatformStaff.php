<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;

/** PLAT-7: a member of the platform team. Control-plane only; has no standing in any tenant. */
class PlatformStaff extends Model
{
    protected $table = 'platform_staff';

    protected $fillable = ['name', 'email', 'password', 'role', 'active', 'failed_logins', 'locked_until', 'last_login_at'];

    protected $hidden = ['password'];

    protected $casts = ['active' => 'boolean', 'locked_until' => 'datetime', 'last_login_at' => 'datetime'];

    public const ABILITIES = [
        'viewer' => ['view'],
        'operator' => ['view', 'operate', 'provision'],
        'support' => ['view', 'impersonate'],
        'admin' => ['view', 'operate', 'provision', 'destructive', 'impersonate'],
    ];

    public function getConnectionName()
    {
        return config('tenancy.control_connection');
    }

    public function can(string $ability): bool
    {
        return in_array($ability, self::ABILITIES[$this->role] ?? [], true);
    }
}
