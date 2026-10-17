<?php

namespace App\Http\Controllers;

use App\Actions\Hearings\ScheduleHearing;
use App\Constants\ModulePermissions;
use App\Http\Requests\Hearing\DestroyManyRequest;
use App\Http\Requests\Hearing\StoreRequest;
use App\Http\Requests\Hearing\UpdateRequest;
use App\Http\Resources\V1\HearingResource;
use App\Models\Hearing;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class HearingController extends Controller
{
    public function __construct(private ScheduleHearing $hearings) {}

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_HEARINGS)) {
            abort(403);
        }

        $query = Hearing::with(['case', 'court', 'hearingTypeName'])->orderBy('id', 'desc');

        // UI-35 bug fix: the case detail page's "Case Hearings" table
        // called this endpoint with no filter at all, so it showed every
        // hearing in the entire firm on every case's page, not just that
        // case's own hearings — the same class of bug already fixed for
        // Case Expenses below. Opt-in filter, existing plain hearings
        // list page and the calendar's from/to usage are unaffected.
        if (request()->filled('case_id')) {
            $query->where('case_id', request()->input('case_id'));
        }

        // Calendar view (FUN-3 frontend): optional ISO date-range filter
        // so the calendar can request just the hearings for the visible
        // month instead of loading every hearing in the system. Leaves
        // the default (no params) behavior — used by the plain list
        // page — completely unchanged.
        if (request()->filled('from')) {
            $query->whereDate('hearing_date', '>=', request()->input('from'));
        }
        if (request()->filled('to')) {
            $query->whereDate('hearing_date', '<=', request()->input('to'));
        }

        $hearings = $this->paginatedOrFull(
            request(),
            $query,
            fn ($row) => HearingResource::make($row)->resolve(),
            25,
            ['hearing_date'],
            ['notes', 'outcome']
        );

        return $this->response(true, 'success', $hearings, 200);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_HEARINGS)) {
            abort(403);
        }

        $hearing = $this->hearings->schedule($this->hearingData($request) + $request->only(['start_time', 'duration_minutes', 'courtroom', 'judicial_officer', 'efiling_reference']), Auth::user());   // FUN-16

        return $this->response(true, 'success', HearingResource::make($hearing->load(['case', 'court', 'hearingTypeName']))->resolve(), 201);
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_HEARINGS)) {
            abort(403);
        }

        $hearing = $this->hearings->change(Hearing::findOrFail($id), $this->hearingData($request)
            + $request->only(array_values(array_filter(['start_time', 'duration_minutes', 'courtroom', 'judicial_officer', 'efiling_reference'], fn ($f) => $request->exists($f)))), Auth::user());   // FUN-16: only when sent

        return $this->response(true, 'success', HearingResource::make($hearing->fresh(['case', 'court', 'hearingTypeName']))->resolve(), 200);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return JsonResponse
     */
    public function destroy($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_HEARINGS)) {
            abort(403);
        }

        $this->hearings->cancel([Hearing::findOrFail($id)], Auth::user());

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * UI-20: bulk delete — see CasesController::destroyMany for the
     * general pattern this mirrors.
     */
    public function destroyMany(DestroyManyRequest $request)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_HEARINGS)) {
            abort(403);
        }

        $hearings = Hearing::whereIn('id', $request->input('ids'))->get();
        $this->hearings->cancel($hearings, Auth::user());

        return $this->response(true, 'success', ['deleted' => $hearings->pluck('id')], 200);
    }

    /**
     * UI-24: undo for the toast shown right after destroy().
     */
    public function restore($id)
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_HEARINGS)) {
            abort(403);
        }

        $hearing = $this->hearings->restore(Hearing::withTrashed()->findOrFail($id));

        return $this->response(true, 'success', $hearing, 200);
    }

    /** @return array<string, mixed> the hearing as sent, date as Y-m-d */
    private function hearingData(Request $request): array
    {
        return ['case_id' => $request->case_id, 'court_id' => $request->court_id, 'hearing_type' => $request->hearing_type,
            'notes' => $request->notes, 'outcome' => $request->outcome,
            'hearing_date' => Carbon::createFromFormat('d/m/Y', $request->hearing_date)->format('Y-m-d')];
    }
}
