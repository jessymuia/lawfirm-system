<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    protected $except = [
        'api/platform/*',      // the operator console signs in and calls with a bearer token, never a cookie session
        'api/v1/csp-report',   // SEC-14: browsers post violation reports themselves, without a CSRF token
    ];
}
