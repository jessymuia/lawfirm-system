<?php

/**
 * SEC-14: response headers that harden the web app in the browser (docs/security/SECURITY_HEADERS.md).
 */
return [
    // Strict-Transport-Security on HTTPS responses: browsers then refuse plain HTTP to this host. 0 = off.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),
    'hsts_include_subdomains' => (bool) env('SECURITY_HSTS_INCLUDE_SUBDOMAINS', true),

    // Content-Security-Policy: report-only (violations are reported, nothing is blocked) until the reports are clean,
    // then enforce. off | report-only | enforce
    'csp_mode' => env('SECURITY_CSP_MODE', 'report-only'),
    'csp' => [
        "default-src 'self'",
        "script-src 'self'",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",   // Vue style bindings; the web fonts' CSS
        "font-src 'self' data: https://fonts.gstatic.com",
        "img-src 'self' data: blob:",
        "connect-src 'self' ".env('SECURITY_CSP_CONNECT', ''),             // e.g. the S3 bucket for direct uploads
        "form-action 'self' https://checkout.stripe.com",
        "frame-ancestors 'self'",
        "base-uri 'self'",
        "object-src 'none'",
    ],
    'csp_report_path' => '/api/v1/csp-report',

    'permissions_policy' => 'camera=(), microphone=(), geolocation=(), payment=(self "https://checkout.stripe.com")',
];
