<?php

namespace App\Http\Controllers;

use App\Http\Requests\Organisation\AssignRequest;
use App\Http\Requests\Organisation\OfficeRequest;
use App\Http\Requests\Organisation\PracticeGroupRequest;
use App\Models\Cases;
use App\Models\Office;
use App\Models\PracticeGroup;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * FUN-18: the firm's offices and practice groups (manage-organisation), and the id+name lists every form uses to pick
 * them (any signed-in person). Removing an office or group that still has people or open matters is refused: move
 * them first, so nothing silently loses its place in the reports.
 */
class OrganisationController extends Controller
{
    /** Dropdown data for every form: names only. */
    public function options(): JsonResponse
    {
        return $this->response(true, 'success', [
            'offices' => Office::orderByDesc('is_head_office')->orderBy('name')->get(['id', 'name', 'code', 'is_head_office']),
            'practice_groups' => PracticeGroup::orderBy('name')->get(['id', 'name', 'code']),
        ], 200);
    }

    public function index(): JsonResponse
    {
        $people = fn ($col) => User::whereNull('platform_staff_id')->whereNotNull($col)->groupBy($col)->selectRaw("{$col} as id, count(*) as n")->pluck('n', 'id');
        $open = fn ($col) => Cases::withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('lifecycle_status', ['closed', 'settled'])
            ->whereNotNull($col)->groupBy($col)->selectRaw("{$col} as id, count(*) as n")->pluck('n', 'id');
        [$officePeople, $groupPeople, $officeOpen, $groupOpen] = [$people('office_id'), $people('practice_group_id'), $open('office_id'), $open('practice_group_id')];

        return $this->response(true, 'success', [
            'offices' => Office::orderByDesc('is_head_office')->orderBy('name')->get()->map(fn ($o) => $o->only(['id', 'name', 'code', 'city', 'address', 'phone', 'email', 'is_head_office'])
                + ['people' => (int) ($officePeople[$o->id] ?? 0), 'open_matters' => (int) ($officeOpen[$o->id] ?? 0)]),
            'practice_groups' => PracticeGroup::with('head:id,name')->orderBy('name')->get()->map(fn ($g) => $g->only(['id', 'name', 'code', 'description', 'head_user_id'])
                + ['head' => $g->head?->name, 'people' => (int) ($groupPeople[$g->id] ?? 0), 'open_matters' => (int) ($groupOpen[$g->id] ?? 0)]),
        ], 200);
    }

    public function storeOffice(OfficeRequest $r): JsonResponse
    {
        $office = DB::transaction(function () use ($r) {
            $d = $r->validated();
            if (! empty($d['is_head_office'])) {
                Office::where('is_head_office', true)->update(['is_head_office' => false]);   // one head office
            }

            return Office::create($d);
        });

        return $this->response(true, 'success', $office, 201);
    }

    public function updateOffice(OfficeRequest $r, int $office): JsonResponse
    {
        $o = Office::findOrFail($office);
        DB::transaction(function () use ($r, $o) {
            $d = $r->validated();
            if (! empty($d['is_head_office'])) {
                Office::where('is_head_office', true)->whereKeyNot($o->id)->update(['is_head_office' => false]);
            }
            $o->update($d);
        });

        return $this->response(true, 'success', $o->fresh(), 200);
    }

    public function destroyOffice(int $office): JsonResponse
    {
        return $this->remove(Office::findOrFail($office), 'office_id', 'office');
    }

    public function storeGroup(PracticeGroupRequest $r): JsonResponse
    {
        return $this->response(true, 'success', PracticeGroup::create($r->validated()), 201);
    }

    public function updateGroup(PracticeGroupRequest $r, int $group): JsonResponse
    {
        $g = PracticeGroup::findOrFail($group);
        $g->update($r->validated());

        return $this->response(true, 'success', $g->fresh(), 200);
    }

    public function destroyGroup(int $group): JsonResponse
    {
        return $this->remove(PracticeGroup::findOrFail($group), 'practice_group_id', 'practice group');
    }

    /**
     * Move people into an office and/or practice group (a field left out is left alone; null clears it). Optionally
     * their open matters follow them, so the reports stay right after a reorganisation.
     */
    public function assign(AssignRequest $r): JsonResponse
    {
        $d = $r->validated();
        $set = array_intersect_key($d, array_flip(['office_id', 'practice_group_id']));
        if ($set === []) {
            return $this->response(false, 'Choose an office or a practice group.', null, 422);
        }
        $moved = DB::transaction(function () use ($d, $set) {
            $people = User::whereIn('id', $d['user_ids'])->whereNull('platform_staff_id')->get();
            foreach ($people as $u) {
                $u->forceFill($set)->save();
            }
            $matters = 0;
            if (! empty($d['apply_to_open_matters'])) {
                $matters = Cases::withoutGlobalScopes()->whereNull('deleted_at')->whereIn('assigned_to', $people->pluck('id'))
                    ->whereNotIn('lifecycle_status', ['closed', 'settled'])->update($set + ['updated_at' => now()]);
            }

            return ['people' => $people->count(), 'matters' => $matters];
        });

        return $this->response(true, "Moved {$moved['people']} people".($moved['matters'] ? " and {$moved['matters']} open matters" : '').'.', $moved, 200);
    }

    private function remove(Office|PracticeGroup $row, string $column, string $what): JsonResponse
    {
        $people = User::where($column, $row->id)->count();
        $matters = Cases::withoutGlobalScopes()->whereNull('deleted_at')->whereNotIn('lifecycle_status', ['closed', 'settled'])->where($column, $row->id)->count();
        if ($people || $matters) {
            return $this->response(false, "This {$what} still has {$people} people and {$matters} open matters. Move them first.", ['people' => $people, 'open_matters' => $matters], 422);
        }
        $row->deleted_by = auth()->id();
        $row->save();
        $row->delete();

        return $this->response(true, 'success', null, 200);
    }
}
