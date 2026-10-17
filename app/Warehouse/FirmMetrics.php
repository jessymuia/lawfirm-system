<?php

namespace App\Warehouse;

use Illuminate\Support\Facades\DB;

/**
 * ENG-26: one day of the active firm, aggregated. Read from the firm's own database with no user scope, and reduced to
 * counts and sums right here: no row of a client, matter, document or note leaves this class.
 */
class FirmMetrics
{
    /** @return array<string, list<array<string, mixed>>> table => rows */
    public function day(string $date, string $tenantKey): array
    {
        $next = date('Y-m-d', strtotime($date.' +1 day'));
        $on = fn (string $table, string $column = 'created_at') => DB::table($table)->where($column, '>=', $date)->where($column, '<', $next);
        $live = fn (string $table) => DB::table($table)->whereNull('deleted_at')->where('created_at', '<', $next);
        $base = ['date' => $date, 'tenant_key' => $tenantKey];

        $daily = $base + [
            'users_total' => $live('users')->count(),
            'users_active' => (int) $on('audits')->whereNotNull('user_id')->distinct()->count('user_id'),
            'matters_total' => $live('cases')->count(),
            'matters_open' => $live('cases')->where('lifecycle_status', 'open')->count(),
            'matters_opened' => $on('cases')->count(),
            'clients_total' => $live('clients')->count(),
            'clients_added' => $on('clients')->count(),
            'hearings_held' => DB::table('hearings')->whereNull('deleted_at')->whereDate('hearing_date', $date)->count(),
            'documents_added' => $on('documents')->count(),
            'document_bytes_added' => (int) $on('documents')->sum('filesize'),
            'document_bytes_total' => (int) $live('documents')->sum('filesize'),
            'tasks_due' => DB::table('tasks')->whereNull('deleted_at')->whereDate('due_date', $date)->count(),
            'hours_logged' => (float) DB::table('time_entries')->whereNull('deleted_at')->whereDate('date', $date)->sum('hours'),
            'hours_billable' => (float) DB::table('time_entries')->whereNull('deleted_at')->whereDate('date', $date)->where('billable', true)->sum('hours'),
        ];

        $money = [];
        $issued = DB::table('invoices')->whereNull('deleted_at')->where('workflow_status', 'submitted')->whereDate('invoice_date', $date)
            ->groupBy('currency')->get(['currency', DB::raw('count(*) as n'), DB::raw('sum(total_amount) as amount')]);
        foreach ($issued as $r) {
            $money[$r->currency]['invoices_issued'] = (int) $r->n;
            $money[$r->currency]['invoiced'] = (float) $r->amount;
        }
        $paid = DB::table('payments')->join('invoices', 'invoices.id', '=', 'payments.invoice_id')->whereNull('payments.deleted_at')
            ->whereDate('payments.payment_date', $date)->groupBy('invoices.currency')
            ->get(['invoices.currency', DB::raw('count(*) as n'), DB::raw('sum(payments.amount) as amount')]);
        foreach ($paid as $r) {
            $money[$r->currency]['payments'] = (int) $r->n;
            $money[$r->currency]['collected'] = (float) $r->amount;
        }
        $timeValue = (float) DB::table('time_entries')->whereNull('deleted_at')->whereDate('date', $date)->sum(DB::raw('hours * hourly_rate'));
        if ($timeValue > 0) {
            $money[(string) config('warehouse.time_currency')]['time_value'] = $timeValue;   // time carries no currency: the firm's
        }
        $moneyRows = [];
        foreach ($money as $currency => $m) {
            $moneyRows[] = array_merge($base, ['currency' => (string) $currency, 'invoices_issued' => 0, 'invoiced' => 0, 'payments' => 0, 'collected' => 0,
                'time_value' => 0], $m);
        }

        $events = $on('audits')->groupBy('event')->orderBy('event')->get(['event', DB::raw('count(*) as n')])
            ->map(fn ($r) => $base + ['event' => (string) $r->event, 'count' => (int) $r->n])->all();

        return ['firm_daily' => [$daily], 'firm_money_daily' => $moneyRows, 'firm_events_daily' => $events];
    }
}
