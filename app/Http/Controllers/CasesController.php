<?php

namespace App\Http\Controllers;

use App\Actions\Matters\ChangeMatterStatus;
use App\Actions\Matters\DeleteMatters;
use App\Actions\Matters\OpenMatter;
use App\Actions\Matters\UpdateMatter;
use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\Document;
use App\Models\DocumentAccess;
use App\Models\Expense;
use App\Models\Hearing;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use OwenIt\Auditing\Models\Audit;

class CasesController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CASES)) {
            abort(403);
        }

        $query = Cases::with(['client', 'attorney', 'court', 'office:id,name', 'practiceGroup:id,name'])->orderBy('id', 'desc');

        // FUN-18: by office / practice group (integers only)
        foreach (['office_id', 'practice_group_id'] as $f) {
            if (request()->filled($f)) {
                $query->where($f, (int) request()->input($f));
            }
        }

        // UI-28: a fixed, known column — never a client-supplied column
        // name — so this is safe to apply directly, unlike the generic
        // sort/search whitelist below which does take arbitrary input.
        if (request()->filled('lifecycle_status')) {
            $query->where('lifecycle_status', request()->input('lifecycle_status'));
        }

        $cases = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => \App\Http\Resources\V1\MatterResource::make($row)->resolve(),
            25,
            ['case_number', 'start_date', 'case_type', 'opposing_party'],
            ['case_number', 'opposing_party', 'police_station']
        );

        return $this->response(true, 'success', $cases, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(\App\Http\Requests\Cases\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_CASES)) {
            abort(403);
        }

        // ENG-9: the rules (conflict clearance FUN-9, office defaults FUN-18, parties register) live in the action
        $case = app(OpenMatter::class)->handle($this->matterData($request), Auth::user(), $request->integer('conflict_check_id') ?: null);

        // ENG-4: return the created resource, not a full re-fetch of every case in the system.
        return $this->response(true, 'success', \App\Http\Resources\V1\MatterResource::make($case->load(['client', 'attorney', 'court']))->resolve(), 201);
    }

    /**
     * The matter fields of a create or update request, in the action's terms (dates Y-m-d). Office, practice group and
     * the client's reference only when the request carries them, so an older client that does not know them leaves them alone.
     *
     * @return array<string, mixed>
     */
    private function matterData(Request $request): array
    {
        return [
            'case_number' => $request->case_number,
            'description' => $request->description,
            'client_id' => $request->client_id,
            'assigned_to' => $request->assigned_to,
            'start_date' => Carbon::createFromFormat('d/m/Y', $request->start_date)->format('Y-m-d'),
            'end_date' => $request->end_date ? Carbon::createFromFormat('d/m/Y', $request->end_date)->format('Y-m-d') : null,
            'police_station' => $request->police_station,
            'court_id' => $request->court_id,
            'opposing_party' => $request->opposing_party,
            'case_type' => $request->case_type,
        ] + $request->only(array_values(array_filter(['office_id', 'practice_group_id', 'client_matter_id'], fn ($f) => $request->exists($f))));
    }

    /**
     * Display the specified resource.
     *
     * @return JsonResponse
     */
    public function show($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CASES)) {
            abort(403);
        }

        if (! $id) {
            abort(404);
        }

        $case = Cases::find($id);

        if (! $case) {
            return $this->response(false, 'error', null, 404);
        }

        $data = [
            'id' => $case->id,
            'assigned' => $case->assigned_to ? (User::withTrashed()->find($case->assigned_to)->name ?? 'Unknown user') : 'Not assigned',
            'case_number' => $case->case_number,
            'client_id' => $case->client_id,
            'client' => Client::withTrashed()->find($case->client_id)->name ?? 'Unknown client',
            'court' => $case->court_id ? (Court::withTrashed()->find($case->court_id)->name ?? 'Unknown court') : '',
            'type' => $case->case_type,
            'police_station' => $case->police_station,
            'start_date' => $case->start_date,
            'opposing_party' => $case->opposing_party,
            'lifecycle_status' => $case->lifecycle_status,
            'description' => $case->description,
            // UI-10/UI-12: the lifecycle stepper renders whatever this
            // map says — single source of truth stays server-side
            // (Cases::transitions(), from MatterStatus) rather than the frontend keeping its
            // own hardcoded copy of the same business rule.
            'lifecycle_transitions' => Cases::transitions(),
        ];

        return $this->response(true, 'success', $data, 200);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(\App\Http\Requests\Cases\UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_CASES)) {
            abort(403);
        }

        $case = app(UpdateMatter::class)->handle(Cases::findOrFail($id), $this->matterData($request), Auth::user());   // ENG-9

        return $this->response(true, 'success', \App\Http\Resources\V1\MatterResource::make($case->fresh(['client', 'attorney', 'court']))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_CASES)) {
            abort(403);
        }

        app(DeleteMatters::class)->handle([Cases::findOrFail($id)], Auth::user());   // ENG-9

        // ENG-4: return just the deleted id, not a full re-fetch.
        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * UI-20: bulk delete — same permission and soft-delete semantics as
     * the single destroy() above, just applied to a batch of ids from
     * the DataTable's selection UI. Confirmation of exactly which rows
     * are affected happens client-side before this is ever called (the
     * bulk action bar's own confirm dialog); this endpoint trusts the
     * ids it's given but still re-checks permission server-side.
     */
    public function destroyMany(\App\Http\Requests\Cases\DestroyManyRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_CASES)) {
            abort(403);
        }

        // ENG-9: all or nothing, a matter under a legal hold (SEC-13) refuses the whole batch
        $deleted = app(DeleteMatters::class)->handle(Cases::whereIn('id', $request->input('ids'))->get(), Auth::user());

        return $this->response(true, 'success', ['deleted' => $deleted], 200);
    }

    /**
     * UI-24: undo for the toast shown right after destroy() — same
     * permission as delete, since restoring is the inverse of the same
     * action. Uses withTrashed() because the default query excludes
     * soft-deleted rows.
     */
    public function restore($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_CASES)) {
            abort(403);
        }

        $case = app(DeleteMatters::class)->restore(Cases::withTrashed()->findOrFail($id));   // ENG-9

        return $this->response(true, 'success', $case, 200);
    }

    /**
     * FUN-6: transition a case's lifecycle status, enforcing the allowed
     * transition graph defined on the model rather than letting the client
     * set any arbitrary status.
     */
    public function transitionStatus(\App\Http\Requests\Cases\TransitionStatusRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_CASES)) {
            abort(403);
        }

        // ENG-9: only the transitions in Cases::transitions() (a refused one answers 422 with the reason)
        $case = app(ChangeMatterStatus::class)->handle(Cases::findOrFail($id), (string) $request->lifecycle_status, Auth::user());

        return $this->response(true, 'success', \App\Http\Resources\V1\MatterResource::make($case->fresh(['client', 'attorney', 'court']))->resolve(), 200);
    }

    public function dropDown(Request $request): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CASES)) {
            abort(403);
        }

        // UI-16: same search+limit treatment as clientsDropDown — see
        // that method's comment.
        $query = Cases::query()->orderByDesc('id');

        if ($request->filled('search')) {
            $search = str_replace(['%', '_'], ['\%', '\_'], $request->input('search'));
            $query->where('case_number', 'like', '%'.$search.'%');
        }

        $cases = $query->limit((int) $request->input('per_page', 20))->get();
        $cases = $cases->map(function ($row) {
            return [
                'id' => $row->id,
                'name' => 'Case Number => '.$row->case_number,
                'client_id' => $row->client_id,
            ];
        });

        return $this->response(true, 'success', $cases, 200);
    }

    public function preview()
    {
        // Bug fix (found via a real E2E run): existence was checked
        // before permission, so a request for a document id that simply
        // doesn't exist 404'd before ever reaching the permission check
        // — for id=1 in a fresh/test database that's every unauthorized
        // request, and more generally it lets an unauthorized caller
        // distinguish "forbidden" from "doesn't exist" by response code,
        // which is a real (if minor) information leak. Permission is
        // checked first now, matching every other controller's ordering.
        if (! Auth::user()->checkPermissionTo(ModulePermissions::VIEW_DOCUMENTS)) {
            $id = \request()->id;
            $doc = Document::find($id);
            if ($doc) {
                DocumentAccess::create([
                    'document_id' => $doc->id,
                    'accessed_by' => auth()->user()->id,
                    'accessed_date' => now(),
                    'action' => 'preview',
                    'ip_address' => \request()->ip(),
                    'outcome' => 'Unsuccessfull',
                    'device_info' => 'device',
                ]);
            }
            abort(403);
        }

        $id = \request()->id;
        $doc = Document::findOrFail($id);

        DocumentAccess::create([
            'document_id' => $doc->id,
            'accessed_by' => auth()->user()->id,
            'accessed_date' => now(),
            'action' => 'preview',
            'ip_address' => \request()->ip(),
            'outcome' => 'success',
            'device_info' => 'device', // $browser['device_type']
        ]);

        // Files may live on either disk depending on when they were
        // uploaded (see FUN-5 migration note on the `disk` column).
        // response()->file() only works for local paths, so S3-backed
        // documents are streamed back via a temporary signed download
        // instead of being read into memory here.
        if ($doc->disk === 's3') {
            return Storage::disk('s3')->response($doc->full_path);
        }

        $storagePath = storage_path('app/');
        $full_path = $storagePath.$doc->full_path;

        return response()->file($full_path);
    }

    /**
     * UI-17: debounced existence check the frontend calls while typing,
     * shown as a non-blocking warning hint before submit — the DB-level
     * unique constraint on (case_number, deleted_at) (see DATA-5
     * migration) remains the actual authority; this is purely a UX
     * shortcut so the person finds out before they submit, not after.
     */
    public function checkCaseNumberExists(Request $request): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CASES)) {
            abort(403);
        }

        $caseNumber = $request->input('case_number');
        if (! $caseNumber) {
            return $this->response(true, 'success', ['exists' => false], 200);
        }

        $query = Cases::where('case_number', $caseNumber);
        if ($request->filled('exclude_id')) {
            $query->where('id', '!=', $request->input('exclude_id'));
        }

        return $this->response(true, 'success', ['exists' => $query->exists()], 200);
    }

    /**
     * UI-11: unified activity feed for a case's Activity tab — merges
     * audit trail rows (create/update/delete/restore) for the case
     * itself and every child record that belongs to it (hearings,
     * documents, expenses, time entries) with document-view/download
     * records (document_accesses), into one chronological list.
     *
     * These are two structurally different data sources (the generic
     * audits table vs. the purpose-built document_accesses table), so
     * this pages by fetching a bounded window from each, merging, and
     * slicing — a single SQL query across both isn't possible since
     * they don't share a schema.
     */
    public function activity(Request $request, $id): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_CASES)) {
            abort(403);
        }

        Cases::findOrFail($id);

        $hearingIds = Hearing::withTrashed()->where('case_id', $id)->pluck('id');
        $documentIds = Document::withTrashed()->where('case_id', $id)->pluck('id');
        $expenseIds = Expense::withTrashed()->where('case_id', $id)->pluck('id');
        $timeEntryIds = TimeEntry::withTrashed()->where('case_id', $id)->pluck('id');

        $childTypesAndIds = [
            [Hearing::class, $hearingIds, 'Hearing'],
            [Document::class, $documentIds, 'Document'],
            [Expense::class, $expenseIds, 'Expense'],
            [TimeEntry::class, $timeEntryIds, 'Time entry'],
        ];

        $auditRows = Audit::where(function ($q) use ($id, $childTypesAndIds) {
            $q->where(function ($qq) use ($id) {
                $qq->where('auditable_type', Cases::class)->where('auditable_id', $id);
            });
            foreach ($childTypesAndIds as [$modelClass, $ids]) {
                if ($ids->isEmpty()) {
                    continue;
                }
                $q->orWhere(function ($qq) use ($modelClass, $ids) {
                    $qq->where('auditable_type', $modelClass)->whereIn('auditable_id', $ids);
                });
            }
        })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        // The audits table has no real `user` relation (it's a generic
        // package table, not aware of this app's User model) — resolve
        // actor names the same manual way as document_accesses below.
        $auditUserNames = User::withTrashed()
            ->whereIn('id', $auditRows->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        $labelFor = function ($modelClass) use ($childTypesAndIds) {
            if ($modelClass === Cases::class) {
                return 'Case';
            }
            foreach ($childTypesAndIds as [$class, , $label]) {
                if ($class === $modelClass) {
                    return $label;
                }
            }

            return 'Record';
        };

        $activityFromAudits = $auditRows->map(function ($audit) use ($labelFor, $auditUserNames) {
            $auditUserId = $audit->getAttribute('user_id');
            $actor = $auditUserNames[$auditUserId] ?? 'System';
            $label = $labelFor($audit->auditable_type);

            return [
                'type' => 'audit',
                'event' => $audit->event,
                'actor' => $actor,
                'description' => "{$actor} {$audit->event} a {$label}",
                'occurred_at' => $audit->getAttribute('created_at'),
            ];
        });

        $documentAccesses = DocumentAccess::whereIn('document_id', $documentIds)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        $accessUserNames = User::withTrashed()
            ->whereIn('id', $documentAccesses->pluck('accessed_by')->unique())
            ->pluck('name', 'id');

        $activityFromAccesses = $documentAccesses->map(function (DocumentAccess $access) use ($accessUserNames) {
            $actor = $accessUserNames[$access->accessed_by] ?? 'Unknown user';

            return [
                'type' => 'document_access',
                'event' => $access->action,
                'actor' => $actor,
                'description' => "{$actor} {$access->action} a document",
                'occurred_at' => $access->getAttribute('created_at'),
            ];
        });

        $combined = $activityFromAudits->concat($activityFromAccesses)
            ->sortByDesc('occurred_at')
            ->values();

        $perPage = (int) $request->input('per_page', 25);
        $page = max(1, (int) $request->input('page', 1));
        $slice = $combined->slice(($page - 1) * $perPage, $perPage)->values();

        return $this->response(true, 'success', [
            'data' => $slice,
            'current_page' => $page,
            'last_page' => (int) ceil(max($combined->count(), 1) / $perPage),
            'total' => $combined->count(),
        ], 200);
    }
}
