<?php

namespace App\Http\Controllers;

use App\Models\Cases;
use App\Models\MatterAccessGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** SEC-10: inspect and change who can see one matter. Every change is audited (MatterAccessGrant is an audited model). */
class MatterAccessController extends Controller
{
    public function index(Cases $case): JsonResponse
    {
        $team = DB::table('attorneys')->join('users', 'users.id', '=', 'attorneys.advocate_id')
            ->where('attorneys.case_id', $case->id)->whereNull('attorneys.deleted_at')
            ->get(['users.id as user_id', 'users.name', 'attorneys.role']);

        $grants = MatterAccessGrant::with('user:id,name')->where('case_id', $case->id)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderBy('id')->get()
            ->map(fn ($g) => [
                'id' => $g->id, 'user_id' => $g->user_id, 'name' => $g->user?->name, 'reason' => $g->reason,
                'expires_at' => $g->expires_at, 'granted_by' => $g->created_by, 'granted_at' => $g->created_at,
            ]);

        return $this->response(true, 'success', [
            'assigned_to' => $case->assigned_to,
            'created_by' => $case->created_by,
            'team' => $team,
            'grants' => $grants,
        ], 200);
    }

    public function store(\App\Http\Requests\MatterAccess\StoreRequest $request, Cases $case): JsonResponse
    {
        $data = $request->validated();

        // One live grant per user and matter: re-granting updates it rather than stacking rows.
        $grant = MatterAccessGrant::where('case_id', $case->id)->where('user_id', $data['user_id'])->first();
        if ($grant) {
            $grant->update(['reason' => $data['reason'] ?? $grant->reason, 'expires_at' => $data['expires_at'] ?? null]);
        } else {
            $grant = MatterAccessGrant::create([
                'case_id' => $case->id, 'user_id' => $data['user_id'],
                'reason' => $data['reason'] ?? null, 'expires_at' => $data['expires_at'] ?? null,
            ]);
        }

        return $this->response(true, 'success', $grant, 201);
    }

    public function destroy(Cases $case, int $grant): JsonResponse
    {
        $row = MatterAccessGrant::where('case_id', $case->id)->findOrFail($grant);
        $row->delete();

        return $this->response(true, 'success', null, 200);
    }
}
