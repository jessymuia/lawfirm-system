<?php

namespace App\Http\Controllers;

use App\Models\SupportAccessSession;
use App\Platform\PlatformAudit;
use App\Platform\PlatformStaff;
use App\SupportAccess\SupportAccessService;
use App\SupportAccess\SupportPolicy;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PLAT-8, platform side. Staff ask; the firm's policy and approval decide. Every step is written twice: to the platform audit
 * log (control plane) and, through the session model and the access log, to the FIRM's own database.
 */
class PlatformSupportController extends Controller
{
    public function __construct(private SupportAccessService $service, private TenantManager $tenants) {}

    private function staff(Request $r): PlatformStaff
    {
        return $r->attributes->get('platform_staff');
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::where('slug', $slug)->firstOrFail();
    }

    public function index(string $slug): JsonResponse
    {
        $tenant = $this->tenant($slug);
        $rows = $this->tenants->runIn($tenant, fn () => SupportAccessSession::orderByDesc('id')->limit(50)->get());

        return response()->json(['policy' => SupportPolicy::for($tenant), 'data' => $rows]);
    }

    public function request(\App\Http\Requests\PlatformSupport\RequestRequest $r, string $slug): JsonResponse
    {
        $d = $r->validated();
        $tenant = $this->tenant($slug);
        $params = ['reason' => $d['reason'], 'minutes' => $d['minutes'] ?? 30, 'mode' => $d['mode'] ?? 'read_only'];

        return $this->guarded($r, 'support.request', $slug, $params, function () use ($tenant, $r, $params) {
            $s = $this->tenants->runIn($tenant, fn () => $this->service->request($this->staff($r), $tenant, $params['reason'], $params['minutes'], $params['mode']));

            return ['status' => 201, 'body' => ['data' => $s, 'next' => $s->status === 'pending' ? 'Waiting for the firm to approve.' : 'Approved by the firm\'s standing policy; start the session.']];
        });
    }

    public function start(Request $r, string $slug, int $id): JsonResponse
    {
        $tenant = $this->tenant($slug);

        return $this->guarded($r, 'support.start', $slug, ['session_id' => $id], function () use ($tenant, $r, $id) {
            $out = $this->tenants->runIn($tenant, fn () => $this->service->start(SupportAccessSession::findOrFail($id), $tenant, $this->staff($r)));
            $domain = $tenant->domains()->value('domain');

            return ['status' => 200, 'body' => [
                'data' => $out['session'],
                'code' => $out['code'],
                'exchange' => $domain ? "https://{$domain}/api/v1/support/session" : null,
                'note' => 'POST {code} to the exchange URL within '.SupportAccessService::CODE_VALID_MINUTES.' minutes; the code works once.',
            ]];
        });
    }

    public function end(Request $r, string $slug, int $id): JsonResponse
    {
        $tenant = $this->tenant($slug);

        return $this->guarded($r, 'support.end', $slug, ['session_id' => $id], function () use ($tenant, $r, $id) {
            $s = $this->tenants->runIn($tenant, function () use ($id, $r) {
                $s = SupportAccessSession::findOrFail($id);
                $staff = $this->staff($r);
                if ($s->platform_staff_id !== $staff->id && ! $staff->can('destructive')) {
                    throw new \RuntimeException('You can only end your own sessions.');
                }

                return $this->service->end($s, 'staff');
            });

            return ['status' => 200, 'body' => ['data' => $s]];
        });
    }

    private function guarded(Request $r, string $action, string $slug, array $params, \Closure $fn): JsonResponse
    {
        try {
            $out = $fn();
        } catch (\RuntimeException $e) {
            PlatformAudit::record($this->staff($r), $action, $slug, $params, 'failed', $e->getMessage(), $r->ip());

            return response()->json(['message' => $e->getMessage()], 422);
        }
        PlatformAudit::record($this->staff($r), $action, $slug, $params, 'ok', null, $r->ip());

        return response()->json($out['body'], $out['status']);
    }
}
