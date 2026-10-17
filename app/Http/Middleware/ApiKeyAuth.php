<?php

namespace App\Http\Middleware;

use App\Entitlements\Entitlements;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * PLAT-11: `api.key:<scope>` authenticates `Authorization: Bearer lfk_<prefix>_<secret>`.
 *
 * Every authentication failure is the same 401 (no hint whether the key exists, expired or was revoked). The key acts as its user, so
 * matter access and ethical walls apply exactly as they do for that person; the key additionally needs the scope AND the user needs the
 * matching permission. Rate limits are per key.
 */
class ApiKeyAuth
{
    public function __construct(private Entitlements $entitlements)
    {
    }

    public static function generate(): array
    {
        $prefix = bin2hex(random_bytes(4));
        $secret = \Illuminate\Support\Str::random(32);

        return ['prefix' => $prefix, 'secret' => $secret, 'token' => "lfk_{$prefix}_{$secret}", 'hash' => hash('sha256', $secret)];
    }

    private function unauthorized()
    {
        return response()->json(['success' => false, 'code' => 'invalid_api_key', 'message' => 'Missing or invalid API key.'], 401);
    }

    public function handle(Request $request, Closure $next, string $scope)
    {
        $token = (string) $request->bearerToken();
        if (! preg_match('/^lfk_([0-9a-f]{8})_([A-Za-z0-9]{32})$/', $token, $m)) {
            return $this->unauthorized();
        }
        $key = DB::table('api_keys')->where('prefix', $m[1])->first();
        // compare the hash even for an unknown prefix so timing does not reveal which prefixes exist
        $ok = hash_equals($key->key_hash ?? str_repeat('0', 64), hash('sha256', $m[2]));
        if (! $key || ! $ok || $key->revoked_at || ($key->expires_at && now()->gte($key->expires_at))) {
            return $this->unauthorized();
        }

        $user = User::whereKey($key->user_id)->whereNull('platform_staff_id')->first();
        if (! $user) {
            return $this->unauthorized();   // the person was deleted: their keys stop with them
        }

        $this->entitlements->require('api_access');

        $scopes = (array) json_decode((string) $key->scopes, true);
        $needed = config("public_api.scopes.{$scope}");
        if ($needed === null || ! in_array($scope, $scopes, true) || ! $user->checkPermissionTo($needed)) {
            return response()->json(['success' => false, 'code' => 'insufficient_scope', 'message' => "This key lacks the {$scope} scope."], 403);
        }

        $limit = (int) ($key->rate_limit ?: config('public_api.rate_limit_per_minute'));
        $rl = "api-key:{$key->id}";
        if (! RateLimiter::attempt($rl, $limit, fn () => true, 60)) {
            $retry = RateLimiter::availableIn($rl);

            return response()->json(['success' => false, 'code' => 'rate_limited', 'message' => 'Too many requests.'], 429, ['Retry-After' => $retry, 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0]);
        }

        // at most one write a minute per key
        DB::table('api_keys')->where('id', $key->id)->where(fn ($q) => $q->whereNull('last_used_at')->orWhere('last_used_at', '<', now()->subMinute()))->update(['last_used_at' => now()]);

        Auth::setUser($user);
        $request->attributes->set('api_key_id', $key->id);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $limit);
        $response->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($rl, $limit));

        return $response;
    }
}
