<?php

namespace App\Http\Middleware;

use App\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * PLAT-8: every request made by an ephemeral support principal is checked against its session and logged in the firm's own
 * database. Expired or revoked => the principal is gone and the request is refused; read-only sessions cannot change anything,
 * whatever permissions a route would otherwise accept. Requests by ordinary users pass straight through.
 */
class SupportSessionGuard
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user || ! $user->platform_staff_id) {
            return $next($request);
        }

        $session = SupportAccessSession::where('user_id', $user->id)->latest('id')->first();
        if ($session && $session->status === 'active' && $session->expires_at->isPast()) {
            app(SupportAccessService::class)->end($session, 'expiry', 'expired');
            $session = $session->fresh();
        }
        if (! $session || ! $session->isActive()) {
            Auth::guard('web')->logout();

            return response()->json(['message' => 'The support session has ended.', 'code' => 'support_session_ended'], 401);
        }
        if ($session->mode === 'read_only' && ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $this->log($session, $request, 403);

            return response()->json(['message' => 'This support session is read-only.', 'code' => 'support_read_only'], 403);
        }

        $request->attributes->set('support_session', $session);

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        if ($session = $request->attributes->get('support_session')) {
            $this->log($session, $request, $response->getStatusCode());
        }
    }

    private function log(SupportAccessSession $session, Request $request, int $status): void
    {
        \Illuminate\Support\Facades\DB::table('support_access_log')->insert([
            'session_id' => $session->id, 'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 255),
            'status' => $status, 'ip' => $request->ip(), 'created_at' => now(),
        ]);
    }
}
