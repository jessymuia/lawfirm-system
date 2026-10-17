<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies;

    /**
     * PLAT-5: behind a TLS-terminating reverse proxy (nginx, a load balancer, an ingress) the app must trust
     * X-Forwarded-* or it generates http:// URLs and drops secure cookies. Set TRUSTED_PROXIES to '*' or a
     * comma-separated list of proxy IPs/CIDRs. Unset = trust none (the safe default).
     */
    protected function proxies()
    {
        $configured = trim((string) config('app.trusted_proxies', ''));
        if ($configured === '') {
            return parent::proxies();
        }

        return $configured === '*' ? '*' : array_values(array_filter(array_map('trim', explode(',', $configured))));
    }

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
