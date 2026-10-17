<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * SEC-14: browser hardening on every response the application sends (the edge may add the same; a header already set
 * is left alone): HSTS on HTTPS, a Content-Security-Policy (report-only by default), nosniff, framing limited to the
 * same origin, a strict referrer policy and no camera, microphone or location access.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $set = function (string $name, string $value) use ($h) {
            $h->has($name) || $h->set($name, $value);
        };

        $set('X-Content-Type-Options', 'nosniff');
        $set('X-Frame-Options', 'SAMEORIGIN');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', (string) config('security_headers.permissions_policy'));

        $maxAge = (int) config('security_headers.hsts_max_age');
        if ($maxAge > 0 && $request->isSecure()) {
            $set('Strict-Transport-Security', "max-age={$maxAge}".(config('security_headers.hsts_include_subdomains') ? '; includeSubDomains' : ''));
        }

        $mode = (string) config('security_headers.csp_mode');
        // a page with its own (nonce-based) policy keeps it alone: a second, generic policy would only report its scripts
        if (in_array($mode, ['report-only', 'enforce'], true) && ! $h->has('Content-Security-Policy')) {
            $policy = implode('; ', array_map('trim', (array) config('security_headers.csp'))).'; report-uri '.config('security_headers.csp_report_path');
            $set($mode === 'enforce' ? 'Content-Security-Policy' : 'Content-Security-Policy-Report-Only', $policy);
        }

        return $response;
    }
}
