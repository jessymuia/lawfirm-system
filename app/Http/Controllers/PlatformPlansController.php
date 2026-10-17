<?php

namespace App\Http\Controllers;

use App\Billing\PlanStore;
use App\Http\Requests\Platform\PlanRequest;
use App\Http\Requests\Platform\ReasonRequest;
use App\Platform\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * PLAT-4: the plan catalogue in the platform console. Staff list, add, edit, archive and delete plans; every change is audited
 * with its reason. A plan that firms are on can be archived (hidden from new sign-ups) but never deleted.
 */
class PlatformPlansController extends Controller
{
    private function db()
    {
        return DB::connection(config('tenancy.control_connection'));
    }

    private function find(string $code): object
    {
        PlanStore::seedIfEmpty();

        return $this->db()->table('plans')->where('code', $code)->first() ?? abort(404, 'No such plan.');
    }

    private function one(string $code): array
    {
        return collect(PlanStore::all())->firstWhere('code', $code) ?? [];
    }

    private function hasPrice(array $d): bool
    {
        return collect((array) ($d['prices'] ?? []))->contains(fn ($v) => $v !== null && $v !== '');
    }

    public function index(): JsonResponse
    {
        PlanStore::sync();

        return response()->json(['data' => PlanStore::all(), 'meta' => [
            'currencies' => array_values((array) config('billing.currencies')),
            'features' => array_values((array) config('entitlements.features')),
            'tax_rate_bp' => (int) config('billing.tax_rate_bp'),
        ]]);
    }

    public function store(PlanRequest $r): JsonResponse
    {
        PlanStore::seedIfEmpty();
        $d = $r->validated();
        if (! $this->hasPrice($d)) {
            return response()->json(['message' => 'Give the plan a monthly price in at least one currency.'], 422);
        }
        if ($this->db()->table('plans')->where('code', $d['code'])->exists()) {
            return response()->json(['message' => 'A plan with that code already exists.'], 409);
        }
        if (array_key_exists($d['code'], (array) config('entitlements.plans'))) {
            return response()->json(['message' => 'That code is reserved by the system. Choose another.'], 422);
        }
        $this->db()->table('plans')->insert(['code' => $d['code'], 'name' => $d['name'], 'description' => $d['description'] ?? null,
            'sort' => (int) $this->db()->table('plans')->max('sort') + 10, 'created_at' => now(), 'updated_at' => now()] + PlanStore::columns($d));
        PlanStore::sync(true);
        PlatformAudit::record($r->attributes->get('platform_staff'), 'plan.create', null, ['code' => $d['code'], 'name' => $d['name'], 'prices' => $d['prices'], 'reason' => $d['reason']], 'ok', null, $r->ip());

        return response()->json(['data' => $this->one($d['code'])], 201);
    }

    public function update(PlanRequest $r, string $code): JsonResponse
    {
        $this->find($code);
        $d = $r->validated();
        if (! $this->hasPrice($d)) {
            return response()->json(['message' => 'Give the plan a monthly price in at least one currency.'], 422);
        }
        // a price taken away would strand firms already billed in that currency
        $before = (array) json_decode((string) $this->find($code)->prices, true);
        foreach ($before as $currency => $amount) {
            if (($d['prices'][$currency] ?? null) === null && $this->db()->table('subscriptions')->where('plan', $code)->where('currency', $currency)->exists()) {
                return response()->json(['message' => "Firms are billed in {$currency} on this plan, so its {$currency} price cannot be removed."], 409);
            }
        }
        $this->db()->table('plans')->where('code', $code)->update(['name' => $d['name'], 'description' => $d['description'] ?? null, 'updated_at' => now()] + PlanStore::columns($d));
        PlanStore::sync(true);
        PlatformAudit::record($r->attributes->get('platform_staff'), 'plan.update', null, ['code' => $code, 'before' => $before, 'prices' => $d['prices'], 'reason' => $d['reason']], 'ok', null, $r->ip());

        return response()->json(['data' => $this->one($code)]);
    }

    public function archive(ReasonRequest $r, string $code): JsonResponse
    {
        return $this->setArchived($r, $code, true);
    }

    public function restore(ReasonRequest $r, string $code): JsonResponse
    {
        return $this->setArchived($r, $code, false);
    }

    private function setArchived(ReasonRequest $r, string $code, bool $archived): JsonResponse
    {
        $this->find($code);
        $d = $r->validated();
        if ($archived && in_array($code, PlanStore::SYSTEM, true)) {
            return response()->json(['message' => 'The trial plan is used for every new sign-up, so it cannot be archived.'], 409);
        }
        $this->db()->table('plans')->where('code', $code)->update(['archived_at' => $archived ? now() : null, 'updated_at' => now()]);
        PlanStore::sync(true);
        PlatformAudit::record($r->attributes->get('platform_staff'), $archived ? 'plan.archive' : 'plan.restore', null, ['code' => $code, 'reason' => $d['reason']], 'ok', null, $r->ip());

        return response()->json(['data' => $this->one($code)]);
    }

    public function destroy(ReasonRequest $r, string $code): JsonResponse
    {
        $this->find($code);
        $d = $r->validated();
        if (in_array($code, PlanStore::SYSTEM, true)) {
            return response()->json(['message' => 'The trial plan is used for every new sign-up, so it cannot be deleted.'], 409);
        }
        if (PlanStore::inUse($code)) {
            return response()->json(['message' => 'Firms are on this plan (or were billed on it), so it cannot be deleted. Archive it instead: it stays for them and is hidden from new sign-ups.'], 409);
        }
        $this->db()->table('plans')->where('code', $code)->delete();
        PlanStore::sync(true);
        PlatformAudit::record($r->attributes->get('platform_staff'), 'plan.delete', null, ['code' => $code, 'reason' => $d['reason']], 'ok', null, $r->ip());

        return response()->json(['data' => ['deleted' => $code]]);
    }
}
