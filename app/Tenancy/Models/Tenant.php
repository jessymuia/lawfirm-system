<?php

namespace App\Tenancy\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** TEN-2: one row fully describes where a tenant's data lives. */
class Tenant extends Model
{
    public const ACTIVE = 'active';

    protected $fillable = ['slug', 'name', 'status', 'region', 'plan', 'entitlements', 'cell', 'database', 'schema_version',
        'migration_status', 'migration_error', 'migration_attempted_at',
        'legal_hold', 'offboarded_at', 'purge_after', 'purged_at', 'support_policy', 'flags', 'schema_drift', 'drift_checked_at', 'read_only_reason',
        'final_export_path', 'final_export_sha256', 'final_export_at', 'final_export_error', 'purge_report', 'files_disk', 'queue', 'trial_ends_at'];

    protected $casts = [
        'entitlements' => 'array',
        'flags' => 'array',
        'schema_drift' => 'array',
        'purge_report' => 'array',
        'final_export_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'drift_checked_at' => 'datetime',
        'migration_attempted_at' => 'datetime',
        'legal_hold' => 'boolean',
        'offboarded_at' => 'datetime',
        'purge_after' => 'datetime',
        'purged_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return config('tenancy.control_connection');
    }

    /** @return HasMany<TenantDomain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }
}
