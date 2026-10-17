<?php

namespace App\Http\Controllers;

use App\Models\SupportAccessSession;
use App\SupportAccess\SupportAccessService;
use App\SupportAccess\SupportPolicy;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * PLAT-8, firm side: the firm sees every platform-support session (and exactly what was requested during it), sets its own
 * policy, approves or denies requests and can revoke access instantly. The one unauthenticated endpoint is the code exchange.
 */
class SupportAccessController extends Controller
{
    public function __construct(private SupportAccessService $service, private TenantManager $tenants) {}

    private function tenant()
    {
        $t = $this->tenants->current();
        abort_unless($t !== null, 404, 'Platform support access is not available on this installation.');

        return $t;
    }

    public function index(Request $r): JsonResponse
    {
        $tenant = $this->tenant();
        $this->service->expireDue();
        $q = SupportAccessSession::orderByDesc('id');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $this->response(true, 'success', [
            'policy' => SupportPolicy::for($tenant),
            'sessions' => $q->limit(200)->get(),
        ], 200);
    }

    public function log(int $id): JsonResponse
    {
        $this->tenant();
        $session = SupportAccessSession::findOrFail($id);

        return $this->response(true, 'success', DB::table('support_access_log')->where('session_id', $session->id)->orderBy('id')->get(), 200);
    }

    public function policy(): JsonResponse
    {
        return $this->response(true, 'success', SupportPolicy::for($this->tenant()), 200);
    }

    public function updatePolicy(\App\Http\Requests\SupportAccess\UpdatePolicyRequest $r): JsonResponse
    {
        $d = $r->validated();
        $tenant = $this->tenant();
        $before = SupportPolicy::for($tenant);
        $after = SupportPolicy::update($tenant, $d);

        // The firm's own audit log records who changed the policy, from what, to what.
        $this->auditPolicyChange($before, $after);

        // Turning support off ends whatever is running right now.
        if (! $after['enabled']) {
            foreach (SupportAccessSession::whereIn('status', ['pending', 'approved', 'active'])->get() as $s) {
                $this->service->end($s, 'firm', 'revoked');
            }
        }

        return $this->response(true, 'success', $after, 200);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->decide($id, fn ($s) => $this->service->approve($s, Auth::user()));
    }

    public function deny(int $id): JsonResponse
    {
        return $this->decide($id, fn ($s) => $this->service->deny($s, Auth::user()));
    }

    public function revoke(int $id): JsonResponse
    {
        return $this->decide($id, fn ($s) => $this->service->end($s, 'firm', 'revoked'));
    }

    /** Public, throttled: trades the one-time code from platform staff for a normal session on this firm's host. */
    public function redeem(\App\Http\Requests\SupportAccess\RedeemRequest $r): JsonResponse
    {
        $d = $r->validated();
        $user = $this->service->redeem($d['code'], $this->tenant());
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'That code is invalid, expired or already used.'], 401);
        }

        Auth::guard('web')->login($user);
        $r->session()->regenerate();

        return $this->response(true, 'success', [$user, true, $user->getRoleNames(), $user->getAllPermissions()->pluck('name')], 200);
    }

    private function decide(int $id, \Closure $fn): JsonResponse
    {
        $this->tenant();
        $session = SupportAccessSession::findOrFail($id);
        try {
            $fn($session);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return $this->response(true, 'success', $session->fresh(), 200);
    }

    private function auditPolicyChange(array $before, array $after): void
    {
        if ($before === $after) {
            return;
        }
        DB::table('audits')->insert([
            'user_type' => Auth::user() ? get_class(Auth::user()) : null, 'user_id' => Auth::id(),
            'event' => 'support_policy_updated', 'auditable_type' => 'support_policy', 'auditable_id' => 0,
            'old_values' => json_encode($before), 'new_values' => json_encode($after),
            'url' => request()->fullUrl(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            'tags' => 'support-access', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
