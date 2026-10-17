<?php

namespace App\Http\Controllers;

use App\Actions\Hearings\RecordHearingOutcome;
use App\Constants\ModulePermissions;
use App\Http\Requests\Today\OutcomeRequest;
use App\Matters\MatterAccess;
use App\Models\Hearing;
use App\Security\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FUN-19: the hearing-day view for a phone: my hearings today and tomorrow (with the client's number to call and where
 * to go), my tasks due, and recording an outcome plus the next date in one step from the corridor.
 */
class TodayController extends Controller
{
    public function show(): JsonResponse
    {
        $user = Auth::user();
        $tz = (string) config('calendar.timezone', 'Africa/Nairobi');
        $today = now($tz)->toDateString();
        $tomorrow = now($tz)->addDay()->toDateString();
        $hearings = collect();
        if ($user->checkPermissionTo(ModulePermissions::LIST_HEARINGS)) {
            $mine = MatterAccess::visibleCaseIds($user)->where(fn ($q) => $q->where('cases.assigned_to', $user->id)
                ->orWhereIn('cases.id', DB::table('attorneys')->select('case_id')->where('advocate_id', $user->id)->whereNull('deleted_at')));
            $hearings = DB::table('hearings')
                ->join('cases', 'cases.id', '=', 'hearings.case_id')
                ->leftJoin('clients', 'clients.id', '=', 'cases.client_id')
                ->leftJoin('courts', 'courts.id', '=', 'hearings.court_id')
                ->leftJoin('hearing_types', 'hearing_types.id', '=', 'hearings.hearing_type')
                ->whereIn('hearings.case_id', $mine)->whereNull('hearings.deleted_at')->whereNull('cases.deleted_at')
                ->whereIn('hearings.hearing_date', [$today, $tomorrow])
                ->orderBy('hearings.hearing_date')->orderByRaw('hearings.start_time is null')->orderBy('hearings.start_time')
                ->get(['hearings.id', 'hearings.case_id', 'hearings.hearing_date', 'hearings.start_time', 'hearings.courtroom', 'hearings.judicial_officer',
                    'hearings.outcome', 'hearings.hearing_type', 'cases.case_number', 'cases.description', 'clients.name as client', 'clients.phone_number as client_phone',
                    DB::raw('coalesce(courts.alias, courts.name) as court'), DB::raw('coalesce(hearing_types.alias, hearing_types.name) as type')])
                ->map(fn ($h) => array_merge((array) $h, ['start_time' => $h->start_time ? substr((string) $h->start_time, 0, 5) : null,   // MySQL TIME is HH:MM:SS
                    'day' => substr((string) $h->hearing_date, 0, 10) === $today ? 'today' : 'tomorrow']));
        }
        $tasks = DB::table('tasks')->where('assigned_to', $user->id)->whereNull('deleted_at')->where('task_status', '!=', 'completed')
            ->where('due_date', '<=', $today)->orderBy('due_date')->limit(20)->get(['id', 'title', 'due_date', 'priority']);

        return $this->response(true, 'success', ['date' => $today, 'hearings' => $hearings->values(), 'tasks' => $tasks,
            'can_record' => $user->checkPermissionTo(ModulePermissions::UPDATE_HEARINGS),
            'hearing_types' => DB::table('hearing_types')->whereNull('deleted_at')->where('archive', 0)->orderBy('name')
                ->get(['id', DB::raw('coalesce(alias, name) as name')])], 200);
    }

    /** The outcome of a hearing, and (if the court gave one) the next date as a new hearing in the same court. */
    public function outcome(OutcomeRequest $r, int $hearing): JsonResponse
    {
        $h = Hearing::findOrFail($hearing);   // matter access applies
        if ($r->validated('next_date') && ! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_HEARINGS)) {
            abort(403, 'Recording the next date needs permission to add hearings.');
        }
        $next = app(RecordHearingOutcome::class)->handle($h, (string) $r->validated('outcome'), $r->safe()->only(['next_date', 'next_time', 'next_type', 'next_notes']), Auth::user());
        SecurityPolicy::audit('hearing_outcome_recorded', 'hearing', (int) $h->id, [], ['next_hearing_id' => $next?->id, 'via' => 'today']);

        return $this->response(true, $next ? 'Outcome and next date saved.' : 'Outcome saved.', ['next_hearing_id' => $next?->id], 200);
    }
}
