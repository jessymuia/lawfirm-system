<?php

namespace App\Http\Controllers;

use App\Platform\PlatformAudit;
use App\Status\Health;
use App\Status\Incidents;
use App\Status\StatusException;
use App\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** PLAT-12: platform-console view of this cell's health (with detail), measured availability and incident management. */
class PlatformStatusController extends Controller
{
    public function __construct(private Health $health, private Incidents $incidents)
    {
    }

    /** Detail the public page withholds: latencies, fixed-phrase reasons, and which firms' databases are unreachable (staff only). */
    public function health(): JsonResponse
    {
        $firms = Tenant::where('status', 'active')->orderBy('slug')->limit(200)->get()
            ->map(fn ($t) => ['slug' => $t->slug, 'database_reachable' => $this->health->tenantReachable($t)])->all();
        $current = $this->health->current();

        return response()->json(['data' => [
            'cell' => config('status.cell'),
            'status' => $this->health->overall(array_map(fn ($c) => $c['status'], $current)),
            'components' => $current,
            'firms' => $firms,
        ]]);
    }

    public function sla(\App\Http\Requests\PlatformStatus\SlaRequest $r): JsonResponse
    {
        $d = $r->validated();
        $days = (int) ($d['days'] ?? 30);

        return response()->json(['data' => ['cell' => config('status.cell'), 'days' => $days, 'target' => config('status.sla_target'), 'components' => $this->health->availability($days)]]);
    }

    public function incidents(): JsonResponse
    {
        return response()->json(['data' => $this->incidents->recent(365)]);
    }

    public function open(\App\Http\Requests\PlatformStatus\OpenRequest $r): JsonResponse
    {
        $d = $r->validated();

        return $this->act($r, 'status.incident.open', $d, fn ($staff) => $this->incidents->open($d, $staff?->id), 201);
    }

    public function update(\App\Http\Requests\PlatformStatus\UpdateRequest $r, int $id): JsonResponse
    {
        $d = $r->validated();

        return $this->act($r, 'status.incident.update', $d + ['incident_id' => $id], fn ($staff) => $this->incidents->update($id, $d['status'], $d['message'], $staff?->id));
    }

    private function act(Request $r, string $action, array $params, \Closure $fn, int $ok = 200): JsonResponse
    {
        $staff = $r->attributes->get('platform_staff');
        try {
            $out = $fn($staff);
        } catch (StatusException $e) {
            PlatformAudit::record($staff, $action, null, $params, 'failed', $e->getMessage(), $r->ip());

            return response()->json(['message' => $e->getMessage()], $e->getCode() >= 400 ? $e->getCode() : 422);
        }
        PlatformAudit::record($staff, $action, null, ['title' => $params['title'] ?? null, 'incident_id' => $out->id, 'status' => $out->status], 'ok', null, $r->ip());
        \Illuminate\Support\Facades\Cache::forget('status:availability:30');

        return response()->json(['data' => $out], $ok);
    }
}
