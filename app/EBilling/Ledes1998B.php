<?php

namespace App\EBilling;

use App\Models\Invoice;
use App\Support\FirmSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * FUN-13: an issued invoice as a LEDES 1998B e-bill, the pipe-delimited format corporate and insurer e-billing systems
 * (and their invoice review rules) accept.
 *
 * Lines: every invoice line becomes one LEDES line. A line billed from a time entry is a fee (F) with the entry's hours,
 * rate, date and timekeeper; a line with an expense code is an expense (E); any other line is a fee at its own quantity
 * and rate, attributed to the matter's responsible lawyer. Line tax has no column in 1998B, so the invoice's tax travels
 * as one invoice-level fee adjustment (IF). INVOICE_TOTAL is the sum of LINE_ITEM_TOTAL, as the format requires, and
 * check() reports if that differs from the invoice's own total.
 */
class Ledes1998B
{
    public const FIELDS = [
        'INVOICE_DATE', 'INVOICE_NUMBER', 'CLIENT_ID', 'LAW_FIRM_MATTER_ID', 'INVOICE_TOTAL', 'BILLING_START_DATE', 'BILLING_END_DATE',
        'INVOICE_DESCRIPTION', 'LINE_ITEM_NUMBER', 'EXP/FEE/INV_ADJ_TYPE', 'LINE_ITEM_NUMBER_OF_UNITS', 'LINE_ITEM_ADJUSTMENT_AMOUNT',
        'LINE_ITEM_TOTAL', 'LINE_ITEM_DATE', 'LINE_ITEM_TASK_CODE', 'LINE_ITEM_EXPENSE_CODE', 'LINE_ITEM_ACTIVITY_CODE', 'TIMEKEEPER_ID',
        'LINE_ITEM_DESCRIPTION', 'LAW_FIRM_ID', 'LINE_ITEM_UNIT_COST', 'TIMEKEEPER_NAME', 'TIMEKEEPER_CLASSIFICATION', 'CLIENT_MATTER_ID',
    ];

    /** The e-bill as text (CRLF line ends, each record closed by "[]"). */
    public function render(Invoice $invoice): string
    {
        $bill = $this->build($invoice);
        $out = ['LEDES1998B[]', implode('|', self::FIELDS).'[]'];
        foreach ($bill['lines'] as $n => $l) {
            $row = [
                $this->date($invoice->invoice_date), $invoice->invoice_number, $bill['client_id'], $bill['matter']->case_number,
                $this->money($bill['total']), $this->date($bill['start']), $this->date($bill['end']), $bill['description'],
                $n + 1, $l['type'], $this->units($l['units']), $this->money($l['adjustment']), $this->money($l['total']), $this->date($l['date']),
                $l['task'], $l['expense'], $l['activity'], $l['timekeeper_id'], $l['description'], $bill['law_firm_id'],
                $this->money($l['unit_cost']), $l['timekeeper_name'], $l['timekeeper_class'], $bill['matter']->client_matter_id,
            ];
            $out[] = implode('|', array_map(fn ($v) => $this->clean((string) $v), $row)).'[]';
        }

        return implode("\r\n", $out)."\r\n";
    }

    /**
     * What would make an e-billing system reject this invoice. Errors stop the export; warnings are worth fixing.
     *
     * @return array{errors: list<string>, warnings: list<string>, lines: int, total: float}
     */
    public function check(Invoice $invoice): array
    {
        $errors = [];
        $warnings = [];
        if ($invoice->workflow_status !== 'submitted') {
            $errors[] = 'Only an issued invoice can be e-billed. Submit it first.';
        }
        $bill = $this->build($invoice);
        if (! $bill['law_firm_id']) {
            $errors[] = 'The firm has no LEDES law firm ID (usually its tax PIN). Set it under Settings → Offices & Groups.';
        }
        if (! $bill['lines']) {
            $errors[] = 'The invoice has no lines.';
        }
        foreach ($bill['lines'] as $n => $l) {
            $at = 'Line '.($n + 1).' ('.mb_strimwidth($l['description'], 0, 40, '…').')';
            if ($l['type'] === 'F' && (! $l['task'] || ! $l['activity'])) {
                $errors[] = "{$at}: a fee needs a UTBMS task and activity code.";
            }
            if ($l['type'] === 'E' && ! $l['expense']) {
                $errors[] = "{$at}: an expense needs a UTBMS expense code.";
            }
            if ($l['type'] === 'F' && ! $l['timekeeper_class']) {
                $warnings[] = "{$at}: {$l['timekeeper_name']} has no timekeeper classification (partner, associate…).";
            }
        }
        if (! $bill['matter']->client_matter_id) {
            $warnings[] = "The matter has no client matter ID; most e-billing systems require the client's own reference.";
        }
        if (abs($bill['total'] - (float) $invoice->total_amount) >= 0.01) {
            $errors[] = 'The lines add up to '.$this->money($bill['total']).' but the invoice total is '.$this->money((float) $invoice->total_amount).'.';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings)), 'lines' => count($bill['lines']), 'total' => $bill['total']];
    }

    /** @return array{lines: list<array<string, mixed>>, total: float, start: ?string, end: ?string, matter: object, client_id: string, law_firm_id: ?string, description: string} */
    private function build(Invoice $invoice): array
    {
        $matter = DB::table('cases')->leftJoin('users', 'users.id', '=', 'cases.assigned_to')->where('cases.id', $invoice->case_id)
            ->first(['cases.id', 'cases.case_number', 'cases.description', 'cases.client_matter_id', 'cases.assigned_to', 'users.name as lawyer', 'users.timekeeper_class']);
        $client = DB::table('clients')->where('id', $invoice->client_id)->first(['id', 'ebilling_client_id', 'public_id']);

        $items = DB::table('invoice_items')->where('invoice_id', $invoice->id)->whereNull('deleted_at')->orderBy('id')->get();
        $time = DB::table('time_entries')->leftJoin('users', 'users.id', '=', 'time_entries.user_id')
            ->whereIn('time_entries.invoice_item_id', $items->pluck('id'))->whereNull('time_entries.deleted_at')
            ->get(['time_entries.*', 'users.name as user_name', 'users.timekeeper_class'])->keyBy('invoice_item_id');

        $lines = [];
        $tax = 0;
        foreach ($items as $item) {
            $amount = round((float) $item->quantity * (float) $item->rate, 2);
            $tax += (float) $item->tax;
            $t = $time->get($item->id);
            if ($t) {
                $units = (float) $t->hours;
                $cost = (float) $t->hourly_rate;
                $lines[] = $this->line('F', $units, $cost, $amount, $t->date, $item->utbms_task_code ?: $t->utbms_task_code, null,
                    $item->utbms_activity_code ?: $t->utbms_activity_code, (string) $t->user_id, $t->user_name, $t->timekeeper_class, $t->description ?: $item->description);
            } elseif ($item->utbms_expense_code) {
                $lines[] = $this->line('E', (float) $item->quantity, (float) $item->rate, $amount, $invoice->invoice_date, null, $item->utbms_expense_code, null,
                    '', '', null, $item->description);
            } else {
                $lines[] = $this->line('F', (float) $item->quantity, (float) $item->rate, $amount, $invoice->invoice_date, $item->utbms_task_code, null,
                    $item->utbms_activity_code, (string) $matter->assigned_to, (string) $matter->lawyer, $matter->timekeeper_class, $item->description);
            }
        }
        $tax = round($tax, 2);
        if ($tax > 0) {
            $lines[] = ['type' => 'IF', 'units' => 0, 'unit_cost' => 0, 'adjustment' => $tax, 'total' => $tax, 'date' => $invoice->invoice_date,
                'task' => null, 'expense' => null, 'activity' => null, 'timekeeper_id' => '', 'timekeeper_name' => '', 'timekeeper_class' => null,
                'description' => 'Tax (VAT) on this invoice'];
        }
        $dates = array_filter(array_column(array_filter($lines, fn ($l) => $l['type'] !== 'IF'), 'date'));

        return [
            'lines' => $lines, 'total' => round(array_sum(array_column($lines, 'total')), 2),
            'start' => $dates ? min($dates) : $invoice->invoice_date, 'end' => $dates ? max($dates) : $invoice->invoice_date,
            'matter' => $matter, 'client_id' => (string) ($client->ebilling_client_id ?: ($client->public_id ?: $client->id)),
            'law_firm_id' => FirmSettings::get('ledes_law_firm_id') ?: null,
            'description' => 'Legal services: '.$matter->case_number.($matter->description ? ' - '.$matter->description : ''),
        ];
    }

    /** @return array<string, mixed> */
    private function line(string $type, float $units, float $cost, float $amount, $date, ?string $task, ?string $expense, ?string $activity,
        string $tkId, string $tkName, ?string $tkClass, string $description): array
    {
        // a line whose amount differs from units x rate (rounding, a manual discount) says so in the adjustment column
        $adjustment = round($amount - round($units * $cost, 2), 2);

        return ['type' => $type, 'units' => $units, 'unit_cost' => $cost, 'adjustment' => $adjustment, 'total' => $amount, 'date' => $date,
            'task' => $task, 'expense' => $expense, 'activity' => $activity, 'timekeeper_id' => $tkId, 'timekeeper_name' => $tkName,
            'timekeeper_class' => $tkClass, 'description' => $description];
    }

    private function date($d): string
    {
        return $d ? Carbon::parse($d)->format('Ymd') : '';
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }

    private function units(float $v): string
    {
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.') ?: '0';
    }

    /** "|" separates fields and "[]" ends a record, so neither may appear inside a value; nor may line breaks. */
    private function clean(string $v): string
    {
        return trim((string) preg_replace('/\s+/', ' ', str_replace(['|', '[', ']'], [' ', '(', ')'], $v)));
    }
}
