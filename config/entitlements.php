<?php

/**
 * PLAT-1: plan -> features and limits. This file is the ONLY place that knows
 * what a plan contains. Domain code asks App\Entitlements\Entitlements
 * (has / limit / within / require); it never reads the deployment mode, the
 * plan name, or the license directly.
 *
 * features: bool. limits: int, or null for unlimited.
 * Per-tenant overrides live in the control DB (tenants.entitlements JSON) and
 * win over the plan, e.g. {"features": {"trust_accounting": true}, "limits": {"users": 25}}.
 */
return [
    // Plan assumed for a tenant whose `plan` column is empty.
    'default_plan' => env('ENTITLEMENT_DEFAULT_PLAN', 'trial'),

    // Plan used when there is no tenant registry (self-hosted / single-tenant).
    // PLAT-2 replaces this source with a signed license file.
    'single_tenant_plan' => env('ENTITLEMENT_PLAN', 'self_hosted'),

    // PLAT-2: signed license for self-hosted installs (ignored in SaaS mode).
    'license' => [
        // false: self-hosted runs on `single_tenant_plan` with no license (dev, tests, existing installs).
        // true : a valid signed license is required; without one the install is read-only.
        'required' => (bool) env('LICENSE_REQUIRED', false),
        // Where the installed license file lives.
        'path' => env('LICENSE_PATH', storage_path('app/license.lic')),
        // Comma-separated base64 Ed25519 public keys (several allow key rotation). The PRIVATE key never ships.
        'public_keys' => array_filter(array_map('trim', explode(',', (string) env('LICENSE_PUBLIC_KEYS', '')))),
        // Used when a license omits grace_days.
        'default_grace_days' => 30,
        // Remembers the latest time seen, so winding the clock back cannot revive an expired license.
        'clock_file' => env('LICENSE_CLOCK_FILE', storage_path('app/license.clock')),
    ],

    'features' => [
        'time_tracking', 'payments', 'trust_accounting', 'exports', 'firm_data_export', 'document_versioning',
        'sso', 'api_access', 'client_portal',
    ],

    // audit_online_months (DATA-11): months the audit trail stays in the live table before audits:archive moves it out
    'limits' => ['users', 'storage_bytes', 'audit_online_months'],

    'plans' => [
        'trial' => [
            'features' => ['time_tracking' => true, 'payments' => true, 'exports' => true, 'document_versioning' => true],
            'limits' => ['users' => 3, 'storage_bytes' => 1 * 1024 ** 3, 'audit_online_months' => 3],
        ],
        'starter' => [
            'features' => ['time_tracking' => true, 'payments' => true, 'exports' => true, 'document_versioning' => true],
            'limits' => ['users' => 5, 'storage_bytes' => 5 * 1024 ** 3, 'audit_online_months' => 12],
        ],
        'professional' => [
            'features' => ['time_tracking' => true, 'payments' => true, 'exports' => true, 'document_versioning' => true,
                'trust_accounting' => true, 'firm_data_export' => true, 'api_access' => true, 'client_portal' => true],
            'limits' => ['users' => 25, 'storage_bytes' => 50 * 1024 ** 3, 'audit_online_months' => 36],
        ],
        'enterprise' => [
            'features' => ['time_tracking' => true, 'payments' => true, 'exports' => true, 'document_versioning' => true,
                'trust_accounting' => true, 'firm_data_export' => true, 'api_access' => true, 'sso' => true, 'client_portal' => true],
            'limits' => ['users' => null, 'storage_bytes' => null, 'audit_online_months' => 84],
        ],
        // Shown when a license is missing, invalid or expired: existing data stays readable/exportable,
        // nothing can be written (see ReadOnlyWhenUnlicensed). No sso / api_access: those are access paths.
        'unlicensed' => [
            'features' => ['time_tracking' => true, 'payments' => true, 'exports' => true, 'document_versioning' => true,
                'trust_accounting' => true, 'firm_data_export' => true, 'client_portal' => true],
            'limits' => ['audit_online_months' => null],   // nothing is archived (or written) while unlicensed
        ],
        // Self-hosted default: everything on, nothing capped, until a license says otherwise.
        'self_hosted' => [
            'features' => ['time_tracking' => true, 'payments' => true, 'exports' => true, 'document_versioning' => true,
                'trust_accounting' => true, 'firm_data_export' => true, 'api_access' => true, 'sso' => true, 'client_portal' => true],
            'limits' => ['users' => null, 'storage_bytes' => null, 'audit_online_months' => null],
        ],
    ],
];
