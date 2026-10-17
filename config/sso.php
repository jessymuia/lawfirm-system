<?php

/** SEC-12: OpenID Connect single sign-on, configured per firm (see docs/SSO.md). */
return [
    // Refuse identity-provider URLs that resolve to private/loopback/link-local addresses (SSRF). Turn off only in tests.
    'block_private_hosts' => (bool) env('SSO_BLOCK_PRIVATE_HOSTS', true),

    // Only https, except when a self-hosted install points at an IdP on its own network.
    'allow_http' => (bool) env('SSO_ALLOW_HTTP', false),

    // Seconds of clock difference tolerated on exp / nbf / iat.
    'leeway' => 60,

    // How long the browser has to come back from the identity provider.
    'state_minutes' => 10,

    // Where the browser lands after a successful / failed sign-in. The login page finishes an SSO sign-in
    // (it loads the session and routes the person on), so both land there by default.
    'success_redirect' => env('SSO_SUCCESS_REDIRECT', '/login?sso=complete'),
    'failure_redirect' => env('SSO_FAILURE_REDIRECT', '/login?sso_error=1'),

    // Discovery and JWKS documents are cached this long (seconds).
    'metadata_ttl' => 3600,
];
