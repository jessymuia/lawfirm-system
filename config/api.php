<?php

return [
    // HTTP-date after which the unversioned /api/* alias may be removed.
    // Minimum notice: 6 months from the first release that ships /api/v1.
    'legacy_sunset' => env('API_LEGACY_SUNSET', 'Wed, 31 Mar 2027 00:00:00 GMT'),

    // ENG-25: requests per minute for a whole firm, across all its users and IPs, on top of the per-user limit.
    // One firm's integration or runaway script must not use up a cell that other firms share. Keyed by the firm's
    // plan; `default` covers firms without a known plan. null = no firm-wide limit (the single-firm/self-hosted default:
    // with one firm on the server there is nobody to protect).
    'firm_rate_limits' => [
        'trial' => (int) env('API_FIRM_RATE_LIMIT_TRIAL', 600),
        'starter' => (int) env('API_FIRM_RATE_LIMIT_STARTER', 1200),
        'professional' => (int) env('API_FIRM_RATE_LIMIT_PROFESSIONAL', 3000),
        'enterprise' => (int) env('API_FIRM_RATE_LIMIT_ENTERPRISE', 6000),
        'default' => (int) env('API_FIRM_RATE_LIMIT', 3000),
    ],
    'firm_rate_limit_single_tenant' => env('API_FIRM_RATE_LIMIT_SINGLE') === null ? null : (int) env('API_FIRM_RATE_LIMIT_SINGLE'),
];
