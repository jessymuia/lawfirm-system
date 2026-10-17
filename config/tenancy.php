<?php

return [
    // 'single': self-hosted / current behaviour, one tenant, no resolution.
    // 'multi':  SaaS. Tenant is resolved from the request host (TEN-3) and the
    //           default DB connection is fail-closed until one is resolved.
    'mode' => env('TENANCY_MODE', 'single'),

    // Control-plane connection (tenant registry, domains, identities).
    'control_connection' => 'control',

    // Connection whose settings are copied for each tenant database; only the
    // `database` name differs per tenant.
    'tenant_connection_template' => env('TENANT_CONNECTION_TEMPLATE', 'mysql'),

    // TEN-6 / TEN-12: identifier of the active tenant. Self-hosted installs
    // (single-tenant collapse mode, TEN-17) keep this constant. In SaaS mode
    // the tenant-resolution middleware (TEN-3) calls TenantContext::activate()
    // per request/job, overriding it.
    'key' => env('TENANT_KEY', 'default'),

    // Tenants get <slug>.<base_domain> unless an explicit domain is given.
    'base_domain' => env('TENANCY_BASE_DOMAIN'),

    // Days between offboarding and permanent deletion (TEN-9).
    'retention_days' => (int) env('TENANCY_RETENTION_DAYS', 30),

    // TEN-9: extra deletion steps (classes implementing App\Tenancy\PurgeStep), e.g. destroying a firm's encryption key
    // or expunging its warehouse rows. Run by `tenants:purge` after the database and files are gone.
    'purge_steps' => [
        \App\Tenancy\Keys\EncryptionKeyPurgeStep::class,   // TEN-13: destroy the firm's data key (crypto-shred)
        \App\Search\SearchPurgeStep::class,                  // ENG-19 search indexes
        \App\Warehouse\WarehousePurgeStep::class,            // ENG-26 warehouse rows
    ],

    // TEN-13: per-firm envelope encryption (docs/ops/FIRM_ENCRYPTION.md). With a master key set, every new firm gets its own
    // data key, wrapped by this key (or, BYOK, by the firm's own AWS KMS key). 32 bytes, `base64:` prefix, like APP_KEY;
    // `php artisan tenants:keys --generate-master-key` prints one. Rotation: new key here, old one in the previous list,
    // then `tenants:keys --all --rewrap`.
    'encryption' => [
        'master_key' => env('TENANCY_MASTER_KEY'),
        'previous_master_keys' => array_filter(explode(',', (string) env('TENANCY_PREVIOUS_MASTER_KEYS', ''))),
        'kms_region' => env('TENANCY_KMS_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
    ],

    // Disks holding firm files under tenants/{slug}/: purged on deletion, and scoped per firm when storage_role_arn is set.
    'storage_disks' => array_filter(explode(',', env('TENANCY_STORAGE_DISKS', 'local'))),

    // TEN-12: IAM role whose policy allows only tenants/${aws:PrincipalTag/tenant}/* (see `php artisan storage:iam-policy`).
    // When set, S3 disks in storage_disks use credentials from AssumeRole tagged with the active firm, never the platform's.
    'storage_role_arn' => env('TENANCY_STORAGE_ROLE_ARN'),
    'storage_session_seconds' => (int) env('TENANCY_STORAGE_SESSION_SECONDS', 3600),

    // PLAT-7: dedicated host for the platform-staff console (/console) and API (/api/platform/*). Unset = console disabled
    // (404 everywhere). Only meaningful in multi mode. Never a tenant domain.
    'platform_host' => env('PLATFORM_ADMIN_HOST'),

    // PLAT-13: host that serves self-service signup (/signup and /api/signup/*) and nothing else, e.g. the marketing
    // site's app.example.com. Unset = signup disabled (404 everywhere). Only meaningful in multi mode. Never a tenant domain.
    'signup_host' => env('TENANCY_SIGNUP_HOST'),

    // Slugs nobody may sign up for (they would collide with hosts the platform uses or reads as official).
    'reserved_slugs' => ['www', 'app', 'api', 'admin', 'platform', 'console', 'signup', 'login', 'auth', 'mail', 'smtp', 'status',
        'help', 'support', 'docs', 'billing', 'static', 'assets', 'cdn', 'test', 'staging', 'demo', 'dev'],

    // Lifetime of a platform-staff bearer token, in hours.
    'platform_token_hours' => (int) env('PLATFORM_TOKEN_HOURS', 8),

    // TEN-4: cell database passwords. A cell row stores only the NAME of its password variable, which must start with
    // CELL_PASSWORD_ (e.g. CELL_PASSWORD_EU1). They are collected here so they survive `config:cache`; with Docker/K8s
    // secrets use CELL_PASSWORD_EU1_FILE and add the name to SECRET_FILE_ALLOW (docs/ops/SECRETS.md).
    'cell_passwords' => array_filter(
        array_merge(getenv() ?: [], $_ENV, $_SERVER),
        fn ($v, $k) => is_string($k) && str_starts_with($k, 'CELL_PASSWORD_') && ! str_ends_with($k, '_FILE') && is_string($v),
        ARRAY_FILTER_USE_BOTH
    ),

    // Local / demo convenience: serve the operator console (/console, /api/platform/*) on the app's own address, next to the
    // firm sign-in, so the sign-in page can offer "Firm" and "Operator" with no separate admin host. Honoured only outside
    // production; in production the console stays on PLATFORM_ADMIN_HOST alone (docs/PLATFORM_CONSOLE.md).
    // The plan, its payments and cancellation belong to the platform operator (the console). Firms see their plan status only.
    // Set true to also let a firm administrator choose or cancel a plan from its own Settings (self-service sign-up flows).
    // Every firm collects its own money and sends its own SMS: when true, M-Pesa, card (Stripe) and SMS never fall back to the
    // platform's accounts, so a firm that has not entered its own credentials simply has that channel switched off.
    'own_credentials' => (bool) env('FIRM_OWN_CREDENTIALS', false),

    'firm_self_service_billing' => (bool) env('FIRM_SELF_SERVICE_BILLING', false),

    'console_on_app_host' => (bool) env('PLATFORM_CONSOLE_ON_APP_HOST', false),

    // SEC-12: platform staff second factor. required = staff without one can only enrol (limited token); optional = may sign in without.
    'platform_mfa' => env('PLATFORM_MFA', 'required'),
];
