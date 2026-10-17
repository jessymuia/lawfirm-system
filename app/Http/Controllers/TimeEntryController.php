<?php

namespace App\Http\Controllers;

use App\Actions\Time\BillTime;
use App\Actions\Time\RecordTime;
use App\Constants\ModulePermissions;
use App\Models\TimeEntry;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class TimeEntryController extends Controller
{
    public function __construct(private RecordTime $recordTime) {}

    public function index(): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_TIME_ENTRIES)) {
            abort(403);
        }

        $query = TimeEntry::with(['case', 'user'])->orderBy('date', 'desc');

        if (request()->has('case_id')) {
            $query->where('case_id', request()->input('case_id'));
        }

        // UI-28: lets the dashboard's "Unbilled Work" KPI deep-link here
        // with the exact same condition it summed — billable(bool)/
        // billed(bool), both fixed known columns.
        if (request()->filled('billable')) {
            $query->where('billable', request()->boolean('billable'));
        }
        if (request()->filled('billed')) {
            $query->where('billed', request()->boolean('billed'));
        }

        // UI-28: matches DashboardController's own unbilled-work scoping
        // — when browsing without a specific case_id (i.e. not a case's
        // own time-entries panel, which intentionally shows every
        // colleague's logged time for that case), a non-admin sees only
        // their own entries, same restriction the dashboard total they
        // clicked through from already applied.
        if (! request()->has('case_id') && ! auth()->user()->hasRole('admin')) {
            $query->where('user_id', auth()->user()->id);
        }

        $entries = $this->paginatedOrFull(request(), $query, fn ($row) => \App\Http\Resources\V1\TimeEntryResource::make($row)->resolve(), 25, ['date', 'hours', 'amount'], ['description']);

        return $this->response(true, 'success', $entries, 200);
    }

    public function store(\App\Http\Requests\TimeEntry\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_TIME_ENTRIES)) {
            abort(403);
        }

        $entry = $this->recordTime->log($this->timeData($request) + ['billable' => $request->boolean('billable', true),
            'utbms_task_code' => $request->input('utbms_task_code'), 'utbms_activity_code' => $request->input('utbms_activity_code')], Auth::user());   // FUN-13

        return $this->response(true, 'success', \App\Http\Resources\V1\TimeEntryResource::make($entry->load(['case', 'user']))->resolve(), 201);
    }

    public function update(\App\Http\Requests\TimeEntry\UpdateRequest $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::UPDATE_TIME_ENTRIES)) {
            abort(403);
        }

        $entry = $this->recordTime->correct(TimeEntry::findOrFail($id), $this->timeData($request) + ['billable' => $request->boolean('billable', true)]
            + $request->only(array_values(array_filter(['utbms_task_code', 'utbms_activity_code'], fn ($f) => $request->exists($f)))), Auth::user());   // FUN-13: codes only when sent

        return $this->response(true, 'success', \App\Http\Resources\V1\TimeEntryResource::make($entry->fresh(['case', 'user']))->resolve(), 200);
    }

    public function destroy($id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::DELETE_TIME_ENTRIES)) {
            abort(403);
        }

        $this->recordTime->remove(TimeEntry::findOrFail($id), Auth::user());

        return $this->response(true, 'success', ['id' => (int) $id], 200);
    }

    /**
     * Generate a draft invoice from a case's outstanding unbilled,
     * billable time entries. Each entry becomes one invoice line item and
     * is marked billed + linked to that line item, so it can never be
     * billed twice.
     */
    public function generateInvoice(\App\Http\Requests\TimeEntry\GenerateInvoiceRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_INVOICES)) {
            abort(403);
        }

        $invoice = app(BillTime::class)->handle((int) $request->case_id, (int) $request->client_id, Auth::user());

        return $this->response(true, 'Draft invoice generated', ['invoice_id' => $invoice->id], 200);
    }

    /** @return array<string, mixed> the entry as sent, date as Y-m-d */
    private function timeData(Request $request): array
    {
        return ['case_id' => $request->case_id, 'description' => $request->description, 'hours' => $request->hours, 'hourly_rate' => $request->hourly_rate,
            'date' => Carbon::createFromFormat('d/m/Y', $request->date)->format('Y-m-d')];
    }
}
