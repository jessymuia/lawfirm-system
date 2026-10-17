<?php

namespace App\Http\Controllers;

use App\Constants\ModulePermissions;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Hearing;
use App\Models\Invoice;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Money\Money;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    /**
     * UI-11: firm-specific KPIs, replacing the generic template dashboard
     * (visitor/traffic charts) with what a law firm's staff actually need
     * at a glance: hearings in the next 7 days, tasks due, unbilled
     * (billable, not-yet-invoiced) work, outstanding invoice balances, and
     * recent case activity.
     *
     * Every scoped section respects the same visibility a user already
     * has via their own module permissions/list scoping — the dashboard
     * shows a personalized summary, not a firm-wide report (that's
     * FUN-4's admin-only export).
     */
    public function index(): JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::VIEW_DASHBOARD)) {
            abort(403);
        }

        $user = Auth::user();
        $isAdmin = $user->hasRole('admin');
        $today = Carbon::today();

        $stats = [
            'total_clients' => Client::count(),
            'total_cases' => Cases::count(),
            'open_cases' => Cases::where('lifecycle_status', 'open')->count(),
        ];

        $upcomingHearings = Hearing::with(['case', 'court'])
            ->whereBetween('hearing_date', [$today->toDateString(), $today->copy()->addDays(7)->toDateString()])
            ->orderBy('hearing_date')
            ->limit(10)
            ->get()
            ->map(fn ($h) => [
                'id' => $h->id,
                'case_id' => $h->case_id,
                'case' => $h->case->case_number ?? 'Unknown case',
                'court' => $h->court?->displayName() ?? 'Unknown court',
                'date' => Carbon::parse($h->hearing_date)->format('d/m/Y'),
                'days_away' => $today->diffInDays(Carbon::parse($h->hearing_date), false),
            ]);

        $tasksQuery = Task::with('assignee')->where('task_status', '!=', 'completed');
        if (! $isAdmin) {
            $tasksQuery->where('assigned_to', $user->id);
        }
        $tasksDue = $tasksQuery->orderBy('due_date')
            ->limit(10)
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->title,
                'assignee' => $t->assignee->name ?? 'Unassigned',
                'due_date' => Carbon::parse($t->due_date)->format('d/m/Y'),
                'overdue' => Carbon::parse($t->due_date)->isPast(),
                'priority' => $t->priority,
            ]);

        $unbilledQuery = TimeEntry::with(['case', 'user'])
            ->where('billable', true)
            ->where('billed', false);
        if (! $isAdmin) {
            $unbilledQuery->where('user_id', $user->id);
        }
        $unbilledTotal = (clone $unbilledQuery)->get()->sum('amount');
        $unbilledByCase = (clone $unbilledQuery)->get()
            ->groupBy('case_id')
            ->map(fn ($entries, $caseId) => [
                'case_id' => $caseId,
                'case' => $entries->first()->case->case_number ?? 'Unknown case',
                'hours' => $entries->sum('hours'),
                'amount' => $entries->sum('amount'),
            ])
            ->values()
            ->take(10);

        $invoicesQuery = Invoice::with('client')->where('workflow_status', 'submitted');
        if (! $isAdmin) {
            $invoicesQuery->where('created_by', $user->id);
        }
        // UI-28: sum the total from the FULL matching set, not just the
        // top-10 slice shown in the panel below — this was previously
        // computed after take(10), so the KPI number silently understated
        // the true outstanding total once a user had more than 10
        // outstanding invoices, and could never match a deep-linked list
        // showing every matching row.
        $allOutstandingInvoices = $invoicesQuery->get()
            ->filter(fn ($inv) => $inv->total_amount > $inv->amount_paid);
        // DATA-14: in the base currency, each invoice at the rate it fixed when issued (one without a rate is not added at face value)
        $outstandingTotal = $allOutstandingInvoices->sum(fn ($inv) => $inv->inBase((float) $inv->total_amount - (float) $inv->amount_paid) ?? 0);

        $outstandingInvoices = $allOutstandingInvoices
            ->sortByDesc(fn ($inv) => $inv->total_amount - $inv->amount_paid)
            ->take(10)
            ->map(fn ($inv) => [
                'id' => $inv->id,
                'invoice_number' => $inv->invoice_number,
                'client_id' => $inv->client_id,
                'client' => $inv->client->name ?? 'Unknown client',
                'outstanding' => $inv->total_amount - $inv->amount_paid,
                'currency' => $inv->currency,   // DATA-14: the outstanding amount is in the invoice's own currency
                'due_date' => Carbon::parse($inv->invoice_due_date)->format('d/m/Y'),
                'overdue' => Carbon::parse($inv->invoice_due_date)->isPast(),
            ]);

        $recentCases = Cases::with('client')
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'case_number' => $c->case_number,
                'client' => $c->client->name ?? 'Unknown client',
                'status' => $c->lifecycle_status,
                'updated_at' => $c->updated_at->diffForHumans(),
            ]);

        // UI-29/UI-30: server-aggregated trend data for the dashboard's
        // two charts — never raw rows shipped to the client for
        // crunching. Six months back through the current month,
        // grouped by month, same admin/non-admin visibility scoping as
        // everything else on this dashboard.
        $sixMonthsAgo = $today->copy()->subMonths(5)->startOfMonth();

        $casesOpenedQuery = Cases::where('start_date', '>=', $sixMonthsAgo);
        $casesClosedQuery = Cases::where('lifecycle_status', 'closed')->where('updated_at', '>=', $sixMonthsAgo);
        if (! $isAdmin) {
            $casesOpenedQuery->where('assigned_to', $user->id);
            $casesClosedQuery->where('assigned_to', $user->id);
        }
        $casesOpenedByMonth = $casesOpenedQuery->get()->groupBy(fn ($c) => Carbon::parse($c->start_date)->format('Y-m'));
        $casesClosedByMonth = $casesClosedQuery->get()->groupBy(fn ($c) => $c->updated_at->format('Y-m'));

        $invoicesTrendQuery = Invoice::where('invoice_date', '>=', $sixMonthsAgo);
        if (! $isAdmin) {
            $invoicesTrendQuery->where('created_by', $user->id);
        }
        $invoicesForTrend = $invoicesTrendQuery->get();
        $invoicedByMonth = $invoicesForTrend->groupBy(fn ($i) => Carbon::parse($i->invoice_date)->format('Y-m'));

        $casesTrend = [];
        $billingTrend = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = $today->copy()->subMonths($i);
            $key = $month->format('Y-m');
            $casesTrend[] = [
                'month' => $month->format('M Y'),
                'opened' => $casesOpenedByMonth->get($key, collect())->count(),
                'closed' => $casesClosedByMonth->get($key, collect())->count(),
            ];
            $monthInvoices = $invoicedByMonth->get($key, collect());
            $billingTrend[] = [
                'month' => $month->format('M Y'),
                'billed' => round($monthInvoices->sum(fn ($i) => $i->inBase((float) $i->total_amount) ?? 0), 2),   // DATA-14: base currency
                'collected' => round($monthInvoices->sum(fn ($i) => $i->inBase((float) $i->amount_paid) ?? 0), 2),
            ];
        }

        return $this->response(true, 'success', [
            'stats' => $stats,
            'upcoming_hearings' => $upcomingHearings,
            'tasks_due' => $tasksDue,
            'unbilled_total' => round($unbilledTotal, 2),
            'unbilled_by_case' => $unbilledByCase,
            'outstanding_invoices' => $outstandingInvoices->values(),
            'outstanding_total' => round($outstandingTotal, 2),
            'currency' => app(Money::class)->baseCurrency(),   // DATA-14: what the totals above are in
            'recent_cases' => $recentCases,
            'cases_trend' => $casesTrend,
            'billing_trend' => $billingTrend,
            'is_admin' => $isAdmin,
        ], 200);
    }
}
