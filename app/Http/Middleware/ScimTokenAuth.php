<?php

namespace App\Http\Middleware;

use App\Entitlements\Entitlements;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * SEC-12: `scim.token` authenticates `Authorization: Bearer scm_<prefix>_<secret>` from an identity provider.
 *
 * The token belongs to the firm, not to a person; it can only reach the SCIM user endpoints. Every failure is the same 401. SCIM is part of
 * the `sso` plan feature. Errors use the SCIM error schema so provisioning clients can read them.
 */
class ScimTokenAuth
{
    public function __construct(private Entitlements $entitlements)
    {
    }

    /** @return array{prefix: string, secret: string, token: string, hash: string} */
    public static function generate(): array
    {
        $prefix = bin2hex(random_bytes(4));
        $secret = \Illuminate\Support\Str::random(40);

        return ['prefix' => $prefix, 'secret' => $secret, 'token' => "scm_{$prefix}_{$secret}", 'hash' => hash('sha256', $secret)];
    }

    public static function error(int $status, string $detail, ?string $scimType = null)
    {
        return response()->json(array_filter([
            'schemas' => ['urn:ietf:params:scim:api:messages:2.0:Error'], 'status' => (string) $status, 'scimType' => $scimType, 'detail' => $detail,
        ]), $status, ['Content-Type' => 'application/scim+json']);
    }

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->bearerToken();
        if (! preg_match('/^scm_([0-9a-f]{8})_([A-Za-z0-9]{40})$/', $token, $m)) {
            return self::error(401, 'Missing or invalid token.');
        }
        $row = DB::table('scim_tokens')->where('prefix', $m[1])->first();
        $ok = hash_equals($row->token_hash ?? str_repeat('0', 64), hash('sha256', $m[2]));   // always compared: no timing hint about prefixes
        if (! $row || ! $ok || $row->revoked_at) {
            return self::error(401, 'Missing or invalid token.');
        }
        if (! $this->entitlements->has('sso')) {
            return self::error(403, 'Provisioning is not part of this firm\'s plan.');
        }
        if (! RateLimiter::attempt("scim:{$row->id}", 300, fn () => true, 60)) {
            return self::error(429, 'Too many requests.');
        }

        DB::table('scim_tokens')->where('id', $row->id)->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))->update(['last_used_at' => now()]);
        $request->attributes->set('scim_token', $row);

        $response = $next($request);
        $response->headers->set('Content-Type', 'application/scim+json');

        return $response;
    }
}
