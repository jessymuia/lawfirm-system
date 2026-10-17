<?php

namespace App\Http\Controllers;

use App\Jobs\SendSmsJob;
use App\Models\Client;
use App\Models\TrustTransaction;
use App\Security\SecurityPolicy;
use App\Trust\Reconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FUN-12: trust three-way reconciliation (reconcile-trust-accounts) and retainer replenishment. See docs/TRUST_RECONCILIATION.md.
 *
 * Rules that keep the record sound: reconciliations go forward in time (a new one must end after the last one
 * signed off), only one draft exists at a time, and a signed-off reconciliation never changes.
 */
class TrustReconciliationController extends Controller
{
    public function __construct(private Reconciliation $rec) {}

    public function index(): JsonResponse
    {
        $rows = DB::table('trust_reconciliations')->whereNull('deleted_at')->orderByDesc('period_end')
            ->paginate(min(100, max(1, (int) request('per_page', 25))), ['id', 'period_end', 'statement_reference', 'bank_balance', 'adjusted_bank_balance',
                'ledger_balance', 'client_ledgers_total', 'balanced', 'state', 'has_exceptions', 'signed_off_at']);

        return $this->response(true, 'success', $rows, 200);
    }

    public function store(\App\Http\Requests\TrustReconciliation\StoreRequest $r): JsonResponse
    {
        $d = $r->validated();
        $q = DB::table('trust_reconciliations')->whereNull('deleted_at');
        if ((clone $q)->where('state', 'draft')->exists()) {
            return $this->response(false, 'Finish or discard the reconciliation in progress first.', null, 422);
        }
        $last = (clone $q)->where('state', 'signed_off')->max('period_end');
        if ($last && $d['period_end'] <= CarbonImmutable::parse($last)->toDateString()) {
            return $this->response(false, "Reconciliations go forward: the last one signed off was for {$last}.", null, 422);
        }
        $id = DB::table('trust_reconciliations')->insertGetId([
            'period_end' => $d['period_end'], 'bank_balance' => $d['bank_balance'], 'statement_reference' => $d['statement_reference'] ?? null,
            'state' => 'draft', 'prepared_by' => Auth::id(), 'created_by' => Auth::id(), 'status' => 1, 'archive' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->response(true, 'success', $this->view($id), 201);
    }

    public function show(int $id): JsonResponse
    {
        $this->find($id);

        return $this->response(true, 'success', $this->view($id), 200);
    }

    /** Tick (or untick) ledger entries that appear on the bank statement. */
    public function clear(\App\Http\Requests\TrustReconciliation\ClearRequest $r, int $id): JsonResponse
    {
        $rec = $this->draft($id);
        $d = $r->validated();
        $date = CarbonImmutable::parse($rec->period_end);
        if ($d['cleared']) {
            $open = collect($this->rec->uncleared($date))->pluck('id')->all();
            $bad = array_diff($d['transaction_ids'], $open);
            if ($bad) {
                return $this->response(false, 'Some of those entries are not outstanding at '.$date->toDateString().': '.implode(', ', $bad), null, 422);
            }
            $rows = array_map(fn ($tid) => ['trust_transaction_id' => $tid, 'trust_reconciliation_id' => $id, 'cleared_on' => $date->toDateString(),
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()], array_values(array_unique($d['transaction_ids'])));
            DB::table('trust_transaction_clearances')->insert($rows);
        } else {
            // only what THIS draft cleared can be unticked
            DB::table('trust_transaction_clearances')->where('trust_reconciliation_id', $id)->whereIn('trust_transaction_id', $d['transaction_ids'])->delete();
        }

        return $this->response(true, 'success', $this->view($id), 200);
    }

    public function addItem(\App\Http\Requests\TrustReconciliation\ItemRequest $r, int $id): JsonResponse
    {
        $this->draft($id);
        DB::table('trust_reconciliation_items')->insert(['trust_reconciliation_id' => $id, 'kind' => 'bank_adjustment'] + $r->validated()
            + ['created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);

        return $this->response(true, 'success', $this->view($id), 201);
    }

    public function removeItem(int $id, int $item): JsonResponse
    {
        $this->draft($id);
        DB::table('trust_reconciliation_items')->where('trust_reconciliation_id', $id)->where('id', $item)->delete();

        return $this->response(true, 'success', $this->view($id), 200);
    }

    public function signOff(\App\Http\Requests\TrustReconciliation\SignOffRequest $r, int $id): JsonResponse
    {
        $this->draft($id);
        try {
            $done = $this->rec->signOff($id, (int) Auth::id(), $r->validated('exception_note'));
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->response(false, $e->getMessage(), $this->view($id), 422);
        }
        SecurityPolicy::audit('trust_reconciliation_signed_off', 'trust_reconciliation', $id, [], [
            'period_end' => $done->period_end, 'balanced' => (bool) $done->balanced, 'has_exceptions' => (bool) $done->has_exceptions,
        ]);

        return $this->response(true, $done->has_exceptions ? 'Signed off with exceptions.' : 'Signed off: the three balances agree.', $this->view($id), 200);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->draft($id);
        DB::transaction(function () use ($id) {
            DB::table('trust_transaction_clearances')->where('trust_reconciliation_id', $id)->delete();
            DB::table('trust_reconciliation_items')->where('trust_reconciliation_id', $id)->delete();
            DB::table('trust_reconciliations')->where('id', $id)->delete();
        });

        return $this->response(true, 'Draft discarded.', null, 200);
    }

    /** The report an auditor asks for: the three figures, what is outstanding, and every client's balance. */
    public function export(int $id): StreamedResponse
    {
        $rec = $this->find($id);
        $v = $this->view($id);

        return response()->streamDownload(function () use ($rec, $v) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Trust three-way reconciliation', $rec->period_end, $rec->statement_reference, $rec->state]);
            fputcsv($out, []);
            foreach ([['Bank statement balance', $rec->bank_balance], ['+ Deposits in transit', $v['figures']['deposits_in_transit']],
                ['- Outstanding disbursements', $v['figures']['outstanding_disbursements']], ['± Bank adjustments', $v['figures']['adjustments']],
                ['= Adjusted bank balance', $v['figures']['bank']], ['Trust ledger balance', $v['figures']['ledger']],
                ['Sum of client ledgers', $v['figures']['clients']], ['Balanced', $v['figures']['balanced'] ? 'yes' : 'NO']] as $row) {
                fputcsv($out, $row);
            }
            if ($rec->exception_note) {
                fputcsv($out, ['Exceptions', $rec->exception_note]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Outstanding at period end', 'Date', 'Type', 'Amount', 'Client', 'Reference', 'Description']);
            foreach ($v['figures']['uncleared'] as $t) {
                fputcsv($out, ['', $t['created_at'], $t['type'], $t['amount'], $t['client'] ?? $t['client_id'], $t['reference_number'], $t['description']]);
            }
            fputcsv($out, []);
            fputcsv($out, ['Client ledger balances', 'Client', 'Balance']);
            foreach ($v['clients'] as $c) {
                fputcsv($out, ['', $c['name'], $c['balance']]);
            }
            fclose($out);
        }, "trust-reconciliation-{$rec->period_end}.csv", ['Content-Type' => 'text/csv']);
    }

    // ---- retainers -----------------------------------------------------------------------------------------------

    public function policies(): JsonResponse
    {
        return $this->response(true, 'success', $this->replenishment(false), 200);
    }

    public function savePolicy(\App\Http\Requests\TrustReconciliation\PolicyRequest $r): JsonResponse
    {
        $d = $r->validated();
        DB::table('trust_retainer_policies')->updateOrInsert(['client_id' => $d['client_id']], [
            'minimum_balance' => $d['minimum_balance'], 'target_balance' => $d['target_balance'], 'deleted_at' => null,
            'updated_by' => Auth::id(), 'created_by' => Auth::id(), 'status' => 1, 'archive' => 0, 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->response(true, 'success', $this->replenishment(false), 200);
    }

    public function deletePolicy(int $client): JsonResponse
    {
        DB::table('trust_retainer_policies')->where('client_id', $client)->update(['deleted_at' => now(), 'deleted_by' => Auth::id()]);

        return $this->response(true, 'success', null, 200);
    }

    /** Clients whose trust balance is below their retainer minimum, with what it takes to reach the target. */
    public function due(): JsonResponse
    {
        return $this->response(true, 'success', $this->replenishment(true), 200);
    }

    public function requestReplenishment(int $client): JsonResponse
    {
        $row = collect($this->replenishment(true))->firstWhere('client_id', $client);
        if (! $row) {
            return $this->response(false, 'That client is not below their retainer minimum.', null, 422);
        }
        $c = Client::findOrFail($client);
        $amount = number_format($row['amount_needed'], 2);
        $message = "Dear {$c->name}, your retainer held in our client account is ".number_format($row['balance'], 2)
            ." against an agreed minimum of ".number_format($row['minimum_balance'], 2).". Kindly deposit {$amount} to restore it. Thank you.";
        SendSmsJob::dispatch((string) $c->phone_number, $message);
        DB::table('trust_replenishment_requests')->insert([
            'client_id' => $client, 'balance' => $row['balance'], 'amount_requested' => $row['amount_needed'], 'channel' => 'sms',
            'message' => mb_substr($message, 0, 500), 'requested_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->response(true, "Replenishment request sent to {$c->name}.", ['amount_requested' => $row['amount_needed']], 200);
    }

    // ---- helpers ---------------------------------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    private function replenishment(bool $dueOnly): array
    {
        $policies = DB::table('trust_retainer_policies as p')->join('clients as c', 'c.id', '=', 'p.client_id')
            ->whereNull('p.deleted_at')->whereNull('c.deleted_at')->get(['p.client_id', 'c.name', 'p.minimum_balance', 'p.target_balance']);
        $last = DB::table('trust_replenishment_requests')->whereIn('client_id', $policies->pluck('client_id'))
            ->groupBy('client_id')->selectRaw('client_id, MAX(created_at) as at')->pluck('at', 'client_id');
        $rows = [];
        foreach ($policies as $p) {
            $balance = TrustTransaction::balanceForClient((int) $p->client_id);
            $below = $balance < (float) $p->minimum_balance;
            if ($dueOnly && ! $below) {
                continue;
            }
            $rows[] = ['client_id' => (int) $p->client_id, 'client' => $p->name, 'balance' => $balance,
                'minimum_balance' => (float) $p->minimum_balance, 'target_balance' => (float) $p->target_balance,
                'below_minimum' => $below, 'amount_needed' => $below ? round((float) $p->target_balance - $balance, 2) : 0.0,
                'last_requested_at' => $last[$p->client_id] ?? null];
        }

        return $rows;
    }

    private function find(int $id): object
    {
        return DB::table('trust_reconciliations')->whereNull('deleted_at')->where('id', $id)->first() ?? abort(404);
    }

    private function draft(int $id): object
    {
        $r = $this->find($id);
        abort_if($r->state !== 'draft', 409, 'A signed-off reconciliation cannot be changed.');

        return $r;
    }

    /** @return array<string, mixed> */
    private function view(int $id): array
    {
        $r = $this->find($id);
        // a signed-off reconciliation is what was signed: never recomputed (later clearings must not change it)
        $figures = $r->state === 'draft' ? $this->rec->compute($id) : [
            'bank' => (float) $r->adjusted_bank_balance, 'ledger' => (float) $r->ledger_balance, 'clients' => (float) $r->client_ledgers_total,
            'bank_vs_ledger' => round((float) $r->adjusted_bank_balance - (float) $r->ledger_balance, 2),
            'ledger_vs_clients' => round((float) $r->ledger_balance - (float) $r->client_ledgers_total, 2),
            'balanced' => (bool) $r->balanced, 'deposits_in_transit' => (float) $r->deposits_in_transit,
            'outstanding_disbursements' => (float) $r->outstanding_disbursements, 'adjustments' => (float) $r->adjustments,
            'uncleared' => json_decode((string) $r->outstanding_snapshot, true) ?: [],
            'negative_clients' => [],
        ];
        $r = $this->find($id);
        $names = DB::table('clients')->pluck('name', 'id');
        foreach ($figures['uncleared'] as &$t) {
            $t['client'] = $names[$t['client_id']] ?? null;
        }
        unset($t);
        $clients = $r->client_snapshot
            ? json_decode($r->client_snapshot, true)
            : collect($this->rec->clientBalances(CarbonImmutable::parse($r->period_end)))
                ->map(fn ($cents, $cid) => ['client_id' => (int) $cid, 'name' => $names[$cid] ?? null, 'balance' => $cents / 100])->values()->all();

        $r->balanced = (bool) $r->balanced;
        $r->has_exceptions = (bool) $r->has_exceptions;
        unset($r->client_snapshot, $r->outstanding_snapshot);   // served as `clients` / `figures.uncleared`

        return [
            'reconciliation' => $r,
            'figures' => $figures,
            'cleared_here' => DB::table('trust_transaction_clearances')->where('trust_reconciliation_id', $id)->pluck('trust_transaction_id'),
            'items' => DB::table('trust_reconciliation_items')->where('trust_reconciliation_id', $id)->get(['id', 'amount', 'description', 'created_at']),
            'clients' => $clients,
        ];
    }
}
