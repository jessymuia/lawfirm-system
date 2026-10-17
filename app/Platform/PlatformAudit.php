<?php

namespace App\Platform;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** PLAT-7 / PLAT-8: append-only record of everything platform staff do. */
class PlatformAudit extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'platform_audit_log';

    protected $guarded = [];

    protected $casts = ['params' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('The platform audit log is append-only.'));
        static::deleting(fn () => throw new \LogicException('The platform audit log is append-only.'));
    }

    public function getConnectionName()
    {
        return config('tenancy.control_connection');
    }

    /** Secrets never reach the log, whatever the caller passes. */
    private const REDACT = ['password', 'admin_password', 'token', 'secret'];

    public static function record(?PlatformStaff $staff, string $action, ?string $tenantSlug = null, array $params = [], string $result = 'ok', ?string $detail = null, ?string $ip = null, ?string $email = null): self
    {
        foreach (self::REDACT as $k) {
            unset($params[$k]);
        }

        return self::create([
            'staff_id' => $staff?->id, 'staff_email' => $staff->email ?? $email, 'action' => $action, 'tenant_slug' => $tenantSlug,
            'params' => $params ?: null, 'result' => $result, 'detail' => $detail ? mb_substr($detail, 0, 500) : null,
            'ip' => $ip ?? request()->ip(),
        ]);
    }
}
