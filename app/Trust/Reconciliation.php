<?php

namespace App\Trust;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FUN-12: trust three-way reconciliation. At a statement date three figures must agree to the cent:
 *
 *   1. the BANK: statement balance + deposits in transit − outstanding disbursements ± bank adjustments
 *   2. the LEDGER: every trust entry up to that date (deposits − disbursements)
 *   3. the CLIENTS: the sum of each client's own ledger balance at that date
 *
 * "In transit" / "outstanding" are ledger entries up to the date that the bank has not cleared yet (cleared ones live
 * in trust_transaction_clearances: the ledger itself is append-only, DATA-10, and never updated). A voided entry
 * counts until the day it was voided, so a past reconciliation still adds up after later voids.
 *
 * All arithmetic is in integer cents; nothing is lost to floating point.
 */
class Reconciliation
{
    private function db()
    {
        return DB::connection();
    }

    /** Ledger entries that exist as of the end of $date (created by then, not voided by then). */
    private function asOf(CarbonImmutable $date)
    {
        $end = $date->endOfDay();

        return $this->db()->table('trust_transactions')->whereNull('trust_transactions.deleted_at')
            ->where('trust_transactions.created_at', '<=', $end)
            ->where(fn ($q) => $q->whereNull('trust_transactions.voided_at')->orWhere('trust_transactions.voided_at', '>', $end));
    }

    private static function cents($v): int
    {
        return (int) round(((float) $v) * 100);
    }

    /** @return array<int, int> client_id => balance in cents */
    public function clientBalances(CarbonImmutable $date): array
    {
        return $this->asOf($date)
            ->selectRaw("client_id, SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END) AS bal")
            ->groupBy('client_id')->pluck('bal', 'client_id')
            ->map(fn ($v) => self::cents($v))->all();
    }

    /** Entries up to $date the bank has not cleared yet (excluding those cleared in $reconciliationId's own draft is the caller's job). */
    public function uncleared(CarbonImmutable $date): array
    {
        return $this->asOf($date)
            ->leftJoin('trust_transaction_clearances as c', 'c.trust_transaction_id', '=', 'trust_transactions.id')
            ->whereNull('c.id')
            ->orderBy('trust_transactions.created_at')
            ->get(['trust_transactions.id', 'client_id', 'case_id', 'type', 'amount', 'description', 'reference_number', 'trust_transactions.created_at'])
            ->all();
    }

    /**
     * Work out the three figures for a reconciliation (draft or not) and store them on it.
     *
     * @return array<string, mixed>
     */
    public function compute(int $id): array
    {
        $r = $this->db()->table('trust_reconciliations')->find($id);
        $date = CarbonImmutable::parse($r->period_end);

        $ledger = 0;
        $clients = $this->clientBalances($date);
        foreach ($clients as $c) {
            $ledger += $c;
        }
        // the ledger total and the clients' total are computed independently, so a client-attribution fault shows
        $ledgerDirect = self::cents($this->asOf($date)->selectRaw("SUM(CASE WHEN type = 'deposit' THEN amount ELSE -amount END) AS t")->value('t'));

        $inTransit = 0;
        $outstanding = 0;
        $open = $this->uncleared($date);
        foreach ($open as $t) {
            $t->type === 'deposit' ? $inTransit += self::cents($t->amount) : $outstanding += self::cents($t->amount);
        }
        $adjust = self::cents($this->db()->table('trust_reconciliation_items')->where('trust_reconciliation_id', $id)->sum('amount'));
        $bank = self::cents($r->bank_balance) + $inTransit - $outstanding + $adjust;
        $balanced = $bank === $ledgerDirect && $ledgerDirect === $ledger;

        if ($r->state === 'draft') {
            $this->db()->table('trust_reconciliations')->where('id', $id)->update([
                'deposits_in_transit' => $inTransit / 100, 'outstanding_disbursements' => $outstanding / 100, 'adjustments' => $adjust / 100,
                'adjusted_bank_balance' => $bank / 100, 'ledger_balance' => $ledgerDirect / 100, 'client_ledgers_total' => $ledger / 100,
                'balanced' => $balanced, 'updated_at' => now(),
            ]);
        }

        return [
            'bank' => $bank / 100, 'ledger' => $ledgerDirect / 100, 'clients' => $ledger / 100,
            'bank_vs_ledger' => ($bank - $ledgerDirect) / 100, 'ledger_vs_clients' => ($ledgerDirect - $ledger) / 100,
            'balanced' => $balanced,
            'deposits_in_transit' => $inTransit / 100, 'outstanding_disbursements' => $outstanding / 100, 'adjustments' => $adjust / 100,
            'uncleared' => array_map(fn ($t) => (array) $t, $open),
            'negative_clients' => array_keys(array_filter($clients, fn ($c) => $c < 0)),
        ];
    }

    /** Freeze it. An unbalanced reconciliation can be signed off only with a written explanation (it is then flagged). */
    public function signOff(int $id, int $by, ?string $exceptionNote): object
    {
        return $this->db()->transaction(function () use ($id, $by, $exceptionNote) {
            $r = $this->db()->table('trust_reconciliations')->where('id', $id)->lockForUpdate()->first();
            if ($r->state !== 'draft') {
                throw new \RuntimeException('This reconciliation is already signed off.');
            }
            $figures = $this->compute($id);
            if (! $figures['balanced'] && trim((string) $exceptionNote) === '') {
                throw new \InvalidArgumentException('The three balances do not agree. Explain the difference to sign off with exceptions, or correct it first.');
            }
            $names = $this->db()->table('clients')->pluck('name', 'id');
            $snapshot = collect($this->clientBalances(CarbonImmutable::parse($r->period_end)))
                ->map(fn ($cents, $cid) => ['client_id' => (int) $cid, 'name' => $names[$cid] ?? null, 'balance' => $cents / 100])->values()->all();
            $this->db()->table('trust_reconciliations')->where('id', $id)->update([
                'state' => 'signed_off', 'signed_off_by' => $by, 'signed_off_at' => now(),
                'has_exceptions' => ! $figures['balanced'], 'exception_note' => $figures['balanced'] ? null : $exceptionNote,
                'client_snapshot' => json_encode($snapshot), 'outstanding_snapshot' => json_encode($figures['uncleared']), 'updated_at' => now(),
            ]);

            return $this->db()->table('trust_reconciliations')->find($id);
        });
    }
}
