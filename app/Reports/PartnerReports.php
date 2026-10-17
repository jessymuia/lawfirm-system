<?php

namespace App\Reports;

use App\Matters\MatterAccess;
use App\Models\User;
use App\Money\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FUN-17: the numbers a managing partner runs the firm on, over a period [from, to], optionally narrowed to an office or
 * practice group (FUN-18) and grouped by lawyer, office or practice group.
 *
 *  - utilisation:   billable hours / available hours (working days x reports.hours_per_day), per lawyer
 *  - realisation:   billing (invoiced value of billed time / its standard value, hours x rate) and collection
 *                   (payments received / fees invoiced in the period)
 *  - wip:           billable time not yet billed at standard value, as at `to`, with its age
 *  - receivables:   balance outstanding on issued invoices as at `to`, aged by days past due
 *  - profitability: per matter: invoiced, collected, expenses, WIP, contribution (collected - expenses)
 *
 * Everything is limited to the matters the person may open (SEC-10 matter access, SEC-11 walls), so a report never
 * shows figures from a matter they could not see. Reads go to the default connection, which is the read replica on the report routes (ENG-23).
 * Money is rounded to cents only at the end. DATA-14: invoice money is in the firm's base currency, each invoice converted at the
 * rate it fixed when issued; invoices with no rate are reported apart (`unconverted`), never added at face value.
 * Time values and expenses are in the base currency already.
 */
class PartnerReports
{
    private CarbonImmutable $from;

    private CarbonImmutable $to;

    /** @param array{office_id?: ?int, practice_group_id?: ?int} $scope */
    public function __construct(private User $viewer, string $from, string $to, private array $scope = [])
    {
        $this->from = CarbonImmutable::parse($from)->startOfDay();
        $this->to = CarbonImmutable::parse($to)->endOfDay();
    }

    private function db()
    {
        return DB::connection();   // the replica on the report routes (ReadFromReplica, ENG-23)
    }

    /** Matters in scope: visible to the viewer, and in the chosen office / practice group. */
    private function matters(): Builder
    {
        $q = $this->db()->table('cases')->whereNull('cases.deleted_at')->whereIn('cases.id', MatterAccess::visibleCaseIds($this->viewer));
        foreach (['office_id', 'practice_group_id'] as $f) {
            if (! empty($this->scope[$f])) {
                $q->where("cases.{$f}", (int) $this->scope[$f]);
            }
        }

        return $q;
    }

    private function inScope(Builder $q, string $caseColumn): Builder
    {
        return $q->whereIn($caseColumn, $this->matters()->select('cases.id'));
    }

    private static function r(float $v): float
    {
        return round($v, 2);
    }

    private static function ratio(float $a, float $b): ?float
    {
        return $b > 0 ? round($a / $b, 4) : null;
    }

    public function workingDays(): int
    {
        $n = 0;
        foreach (CarbonPeriod::create($this->from, $this->to->startOfDay()) as $d) {
            $n += $d->isWeekday() ? 1 : 0;
        }

        return $n;
    }

    /** @return list<array<string, mixed>> per lawyer */
    public function utilisation(): array
    {
        $available = $this->workingDays() * (float) config('reports.hours_per_day', 7);
        $rows = $this->inScope($this->db()->table('time_entries as t')->join('users as u', 'u.id', '=', 't.user_id'), 't.case_id')
            ->whereNull('t.deleted_at')->whereBetween('t.date', [$this->from->toDateString(), $this->to->toDateString()])
            ->groupBy('t.user_id', 'u.name', 'u.office_id', 'u.practice_group_id')
            ->selectRaw('t.user_id, u.name, u.office_id, u.practice_group_id,
                SUM(t.hours) AS hours, SUM(CASE WHEN t.billable THEN t.hours ELSE 0 END) AS billable_hours,
                SUM(CASE WHEN t.billable THEN t.hours * t.hourly_rate ELSE 0 END) AS billable_value')
            ->orderBy('u.name')->get();

        return $rows->map(fn ($r) => [
            'user_id' => (int) $r->user_id, 'lawyer' => $r->name, 'office_id' => $r->office_id, 'practice_group_id' => $r->practice_group_id,
            'hours' => self::r((float) $r->hours), 'billable_hours' => self::r((float) $r->billable_hours),
            'available_hours' => self::r($available), 'utilisation' => self::ratio((float) $r->billable_hours, $available),
            'billable_value' => self::r((float) $r->billable_value),
        ])->all();
    }

    /** @return array{0: string, 1: list<string>} DATA-14: SQL for an invoice's rate to the base currency (NULL: unconverted) */
    private function fx(string $alias): array
    {
        return app(Money::class)->rateSql($alias);
    }

    /**
     * DATA-14: issued invoices in scope that have no rate to the base currency (issued before rates were fixed, in another
     * currency). They are left out of every converted total and listed here instead, never silently added at face value.
     *
     * @return list<array{currency: string, invoices: int, amount: float}>
     */
    private function unconverted(?array $range = null): array
    {
        [$fx, $b] = $this->fx('v');
        $q = $this->inScope($this->db()->table('invoices as v'), 'v.case_id')->whereNull('v.deleted_at')->where('v.workflow_status', 'submitted')
            ->whereRaw("{$fx} IS NULL", $b);
        $range ? $q->whereBetween('v.invoice_date', $range) : $q->where('v.invoice_date', '<=', $this->to->toDateString());

        return $q->groupBy('v.currency')->orderBy('v.currency')->selectRaw('v.currency, COUNT(*) AS n, SUM(v.total_amount) AS amount')->get()
            ->map(fn ($r) => ['currency' => (string) $r->currency, 'invoices' => (int) $r->n, 'amount' => self::r((float) $r->amount)])->all();
    }

    /** @return array<string, mixed> money in the firm's base currency (`currency`), at each invoice's own fixed rate */
    public function realisation(): array
    {
        [$fx, $b] = $this->fx('v');
        $range = [$this->from->toDateString(), $this->to->toDateString()];
        // billing realisation: billed time worked in the period, its standard value vs what its invoice lines charged
        $billed = $this->inScope($this->db()->table('time_entries as t'), 't.case_id')
            ->join('invoice_items as i', 'i.id', '=', 't.invoice_item_id')
            ->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->whereNull('t.deleted_at')->whereNull('i.deleted_at')->whereNull('v.deleted_at')->where('v.workflow_status', '!=', 'void')
            ->whereBetween('t.date', $range)
            ->groupBy('i.id', 'i.rate', 'i.quantity', 'v.fx_rate', 'v.currency')
            ->selectRaw("i.id, i.rate * i.quantity * {$fx} AS charged, SUM(t.hours * t.hourly_rate) AS standard", $b)->get()
            ->filter(fn ($r) => $r->charged !== null);   // a draft in another currency has no rate yet: not billed in base terms
        $standard = (float) $billed->sum('standard');
        $charged = (float) $billed->sum('charged');

        $invoiced = (float) $this->inScope($this->db()->table('invoices as v'), 'v.case_id')->whereNull('v.deleted_at')
            ->where('v.workflow_status', 'submitted')->whereBetween('v.invoice_date', $range)->selectRaw("SUM(v.subtotal * {$fx}) AS s", $b)->value('s');
        $collected = (float) $this->inScope($this->db()->table('payments as p')->join('invoices as v', 'v.id', '=', 'p.invoice_id'), 'v.case_id')
            ->whereNull('p.deleted_at')->whereBetween('p.payment_date', $range)->selectRaw("SUM(p.amount * {$fx}) AS s", $b)->value('s');

        return [
            'currency' => app(Money::class)->baseCurrency(),
            'standard_value_billed' => self::r($standard), 'amount_charged' => self::r($charged),
            'billing_realisation' => self::ratio($charged, $standard),
            'fees_invoiced' => self::r($invoiced), 'collected' => self::r($collected),
            'collection_realisation' => self::ratio($collected, $invoiced),
            'unconverted' => $this->unconverted($range),
        ];
    }

    /** @return array{total: float, hours: float, buckets: array<string, float>, matters: list<array<string, mixed>>} */
    public function wip(): array
    {
        $rows = $this->inScope($this->db()->table('time_entries as t')->join('cases as c', 'c.id', '=', 't.case_id'), 't.case_id')
            ->whereNull('t.deleted_at')->where('t.billable', true)->where('t.billed', false)->whereNull('t.invoice_item_id')
            ->where('t.date', '<=', $this->to->toDateString())
            ->get(['t.case_id', 'c.case_number', 't.date', 't.hours', 't.hourly_rate']);
        $buckets = ['0-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
        $matters = [];
        foreach ($rows as $r) {
            $v = (float) $r->hours * (float) $r->hourly_rate;
            $age = (int) CarbonImmutable::parse($r->date)->diffInDays($this->to->startOfDay());
            $buckets[$age <= 30 ? '0-30' : ($age <= 60 ? '31-60' : ($age <= 90 ? '61-90' : '90+'))] += $v;
            $m = $matters[$r->case_id] ?? ['case_id' => (int) $r->case_id, 'case_number' => $r->case_number, 'hours' => 0.0, 'value' => 0.0, 'oldest' => $r->date];
            $m['hours'] += (float) $r->hours;
            $m['value'] += $v;
            $m['oldest'] = min($m['oldest'], $r->date);
            $matters[$r->case_id] = $m;
        }
        $matters = array_values(array_map(fn ($m) => ['hours' => self::r($m['hours']), 'value' => self::r($m['value'])] + $m, $matters));
        usort($matters, fn ($a, $b) => $b['value'] <=> $a['value']);

        return ['total' => self::r(array_sum($buckets)), 'hours' => self::r((float) $rows->sum('hours')),
            'buckets' => array_map([self::class, 'r'], $buckets), 'matters' => $matters];
    }

    /**
     * Outstanding amounts in the base currency at each invoice's fixed rate; each invoice also says what is owed in its
     * own currency (what the client actually pays).
     *
     * @return array{currency: string, total: float, buckets: array<string, float>, invoices: list<array<string, mixed>>, unconverted: list<array<string, mixed>>}
     */
    public function receivables(): array
    {
        [$fx, $b] = $this->fx('v');
        $paidBy = $this->db()->table('payments')->whereNull('deleted_at')->where('payment_date', '<=', $this->to->toDateString())
            ->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) AS paid')->pluck('paid', 'invoice_id');
        $invoices = $this->inScope($this->db()->table('invoices as v')->join('clients as c', 'c.id', '=', 'v.client_id'), 'v.case_id')
            ->whereNull('v.deleted_at')->where('v.workflow_status', 'submitted')->where('v.invoice_date', '<=', $this->to->toDateString())
            ->select(['v.id', 'v.invoice_number', 'v.case_id', 'c.name as client', 'v.invoice_date', 'v.invoice_due_date', 'v.total_amount', 'v.currency'])
            ->selectRaw("{$fx} AS fx", $b)->get();
        $buckets = ['current' => 0.0, '1-30' => 0.0, '31-60' => 0.0, '61-90' => 0.0, '90+' => 0.0];
        $open = [];
        $unconverted = [];
        foreach ($invoices as $v) {
            $due = round((float) $v->total_amount - (float) ($paidBy[$v->id] ?? 0), 2);
            if ($due <= 0) {
                continue;
            }
            if ($v->fx === null) {
                $u = $unconverted[$v->currency] ?? ['currency' => (string) $v->currency, 'invoices' => 0, 'amount' => 0.0];
                $u['invoices']++;
                $u['amount'] = round($u['amount'] + $due, 2);
                $unconverted[$v->currency] = $u;

                continue;
            }
            $inBase = round($due * (float) $v->fx, 2);
            $late = (int) CarbonImmutable::parse($v->invoice_due_date)->startOfDay()->diffInDays($this->to->startOfDay(), false);
            $bucket = $late <= 0 ? 'current' : ($late <= 30 ? '1-30' : ($late <= 60 ? '31-60' : ($late <= 90 ? '61-90' : '90+')));
            $buckets[$bucket] += $inBase;
            $open[] = ['invoice_id' => (int) $v->id, 'invoice_number' => $v->invoice_number, 'client' => $v->client, 'case_id' => (int) $v->case_id,
                'due_date' => $v->invoice_due_date, 'days_overdue' => max(0, $late), 'bucket' => $bucket, 'outstanding' => $inBase,
                'currency' => (string) ($v->currency ?: app(Money::class)->baseCurrency()), 'outstanding_in_currency' => $due];
        }
        usort($open, fn ($a, $b) => $b['days_overdue'] <=> $a['days_overdue']);
        ksort($unconverted);

        return ['currency' => app(Money::class)->baseCurrency(), 'total' => self::r(array_sum($buckets)),
            'buckets' => array_map([self::class, 'r'], $buckets), 'invoices' => $open, 'unconverted' => array_values($unconverted)];
    }

    /** @return list<array<string, mixed>> per matter, most profitable first */
    public function profitability(): array
    {
        $range = [$this->from->toDateString(), $this->to->toDateString()];
        $matters = $this->matters()->leftJoin('clients as cl', 'cl.id', '=', 'cases.client_id')
            ->leftJoin('users as u', 'u.id', '=', 'cases.assigned_to')
            ->get(['cases.id', 'cases.case_number', 'cl.name as client', 'u.name as lawyer', 'cases.office_id', 'cases.practice_group_id']);
        $ids = $matters->pluck('id');
        $sum = fn (Builder $q, string $col) => $q->groupBy('case_id')->selectRaw("case_id, SUM({$col}) AS v")->pluck('v', 'case_id');
        [$fx, $b] = $this->fx('v');   // DATA-14: in the base currency, at each invoice's fixed rate
        $invoiced = $this->db()->table('invoices as v')->whereIn('v.case_id', $ids)->whereNull('v.deleted_at')->where('v.workflow_status', 'submitted')
            ->whereBetween('v.invoice_date', $range)->groupBy('v.case_id')->selectRaw("v.case_id, SUM(v.subtotal * {$fx}) AS v", $b)->pluck('v', 'case_id');
        $collected = $this->db()->table('payments as p')->join('invoices as v', 'v.id', '=', 'p.invoice_id')->whereIn('v.case_id', $ids)
            ->whereNull('p.deleted_at')->whereBetween('p.payment_date', $range)->groupBy('v.case_id')->selectRaw("v.case_id, SUM(p.amount * {$fx}) AS v", $b)->pluck('v', 'case_id');
        $expenses = $sum($this->db()->table('expenses')->whereIn('case_id', $ids)->whereNull('deleted_at')->whereBetween('date', $range), 'amount');
        $hours = $sum($this->db()->table('time_entries')->whereIn('case_id', $ids)->whereNull('deleted_at')->whereBetween('date', $range), 'hours');
        $wip = collect($this->wip()['matters'])->pluck('value', 'case_id');

        $rows = [];
        foreach ($matters as $m) {
            $row = ['case_id' => (int) $m->id, 'case_number' => $m->case_number, 'client' => $m->client, 'lawyer' => $m->lawyer,
                'office_id' => $m->office_id, 'practice_group_id' => $m->practice_group_id,
                'hours' => self::r((float) ($hours[$m->id] ?? 0)), 'invoiced' => self::r((float) ($invoiced[$m->id] ?? 0)),
                'collected' => self::r((float) ($collected[$m->id] ?? 0)), 'expenses' => self::r((float) ($expenses[$m->id] ?? 0)),
                'wip' => self::r((float) ($wip[$m->id] ?? 0))];
            if ($row['hours'] == 0 && $row['invoiced'] == 0 && $row['collected'] == 0 && $row['expenses'] == 0 && $row['wip'] == 0) {
                continue;   // nothing happened on it in the period
            }
            $row['contribution'] = self::r($row['collected'] - $row['expenses']);
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        return $rows;
    }

    /**
     * Matter rows rolled up by lawyer, office or practice group.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    public static function rollUp(array $rows, string $by, array $names): array
    {
        $key = ['lawyer' => 'lawyer', 'office' => 'office_id', 'practice_group' => 'practice_group_id'][$by];
        $out = [];
        foreach ($rows as $r) {
            $k = $r[$key] ?? null;
            $g = $out[(string) $k] ?? ['group' => $by === 'lawyer' ? ($k ?? 'Unassigned') : ($names[$k] ?? 'None'), 'matters' => 0, 'hours' => 0.0, 'invoiced' => 0.0, 'collected' => 0.0, 'expenses' => 0.0, 'wip' => 0.0, 'contribution' => 0.0];
            $g['matters']++;
            foreach (['hours', 'invoiced', 'collected', 'expenses', 'wip', 'contribution'] as $f) {
                $g[$f] = round($g[$f] + $r[$f], 2);
            }
            $out[(string) $k] = $g;
        }
        $out = array_values($out);
        usort($out, fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        return $out;
    }
}
