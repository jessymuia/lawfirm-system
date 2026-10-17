<?php

namespace App\Http\Controllers;

use App\Http\Requests\Reports\ReportRequest;
use App\Models\Office;
use App\Models\PracticeGroup;
use App\Reports\PartnerReports;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FUN-17: partner reports (view-partner-reports). Each answers JSON, or CSV with ?format=csv. See docs/PARTNER_REPORTS.md.
 */
class ReportsController extends Controller
{
    private function engine(ReportRequest $r): PartnerReports
    {
        $d = $r->validated();

        return new PartnerReports(Auth::user(), $d['from'], $d['to'], array_intersect_key($d, array_flip(['office_id', 'practice_group_id'])));
    }

    /** The headline numbers on one screen. */
    public function summary(ReportRequest $r)
    {
        $e = $this->engine($r);
        $util = collect($e->utilisation());
        $wip = $e->wip();
        $ar = $e->receivables();

        return $this->response(true, 'success', [
            'period' => $r->only(['from', 'to']),
            'billable_hours' => round((float) $util->sum('billable_hours'), 2),
            'utilisation' => ($avail = (float) $util->sum('available_hours')) > 0 ? round($util->sum('billable_hours') / $avail, 4) : null,
            'realisation' => $e->realisation(),
            'wip' => ['total' => $wip['total'], 'buckets' => $wip['buckets']],
            'receivables' => ['total' => $ar['total'], 'buckets' => $ar['buckets']],
        ], 200);
    }

    public function utilisation(ReportRequest $r)
    {
        $rows = $this->engine($r)->utilisation();

        return $this->answer($r, $rows, 'utilisation', ['lawyer', 'hours', 'billable_hours', 'available_hours', 'utilisation', 'billable_value']);
    }

    public function realisation(ReportRequest $r)
    {
        return $this->response(true, 'success', $this->engine($r)->realisation(), 200);
    }

    public function wip(ReportRequest $r)
    {
        $w = $this->engine($r)->wip();

        return $r->input('format') === 'csv'
            ? $this->csv('wip', ['case_number', 'hours', 'value', 'oldest'], $w['matters'])
            : $this->response(true, 'success', $w, 200);
    }

    public function receivables(ReportRequest $r)
    {
        $a = $this->engine($r)->receivables();

        return $r->input('format') === 'csv'
            ? $this->csv('receivables', ['invoice_number', 'client', 'due_date', 'days_overdue', 'bucket', 'outstanding'], $a['invoices'])
            : $this->response(true, 'success', $a, 200);
    }

    public function profitability(ReportRequest $r)
    {
        $rows = $this->engine($r)->profitability();
        if ($by = $r->input('group_by')) {
            $names = match ($by) {
                'office' => Office::withTrashed()->pluck('name', 'id')->all(),
                'practice_group' => PracticeGroup::withTrashed()->pluck('name', 'id')->all(),
                default => [],
            };
            $rows = PartnerReports::rollUp($rows, $by, $names);

            return $this->answer($r, $rows, "profitability-by-{$by}", ['group', 'matters', 'hours', 'invoiced', 'collected', 'expenses', 'wip', 'contribution']);
        }

        return $this->answer($r, $rows, 'profitability', ['case_number', 'client', 'lawyer', 'hours', 'invoiced', 'collected', 'expenses', 'wip', 'contribution']);
    }

    private function answer(ReportRequest $r, array $rows, string $name, array $cols)
    {
        return $r->input('format') === 'csv' ? $this->csv($name, $cols, $rows) : $this->response(true, 'success', $rows, 200);
    }

    private function csv(string $name, array $cols, array $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($cols, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $cols);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($c) => $row[$c] ?? '', $cols));
            }
            fclose($out);
        }, "{$name}-".request('from').'-'.request('to').'.csv', ['Content-Type' => 'text/csv']);
    }
}
