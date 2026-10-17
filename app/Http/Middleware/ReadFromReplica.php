<?php

namespace App\Http\Middleware;

use App\Database\ReadReplica;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ENG-23: the route only reads, so it reads from the replica when there is a fit one (App\Database\ReadReplica).
 * The answer says where its figures came from (X-Data-Source) and how many seconds behind they were (X-Data-Lag), so a
 * screen can say "figures as of a few minutes ago". Put it last, after authentication and authorization.
 */
class ReadFromReplica
{
    public function __construct(private ReadReplica $replica) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $this->replica->read(fn () => $next($request));
        $last = $this->replica->last();
        $response->headers->set('X-Data-Source', $last['source'] ?? 'primary');
        if (($last['lag_seconds'] ?? null) !== null) {
            $response->headers->set('X-Data-Lag', (string) $last['lag_seconds']);
        }

        return $response;
    }
}
