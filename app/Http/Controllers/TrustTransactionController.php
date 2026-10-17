<?php

namespace App\Http\Controllers;

use App\Actions\Trust\PostTrustEntry;
use App\Actions\Trust\VoidTrustEntry;
use App\Constants\ModulePermissions;
use App\Models\TrustTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class TrustTransactionController extends Controller
{
    /**
     * Ledger for a single client, most recent first, plus the current
     * balance. A client_id is required — trust funds are always tracked
     * per client, never as one firm-wide pool.
     */
    public function index(\App\Http\Requests\TrustTransaction\IndexRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::LIST_TRUST_TRANSACTIONS)) {
            abort(403);
        }

        $query = TrustTransaction::with(['case', 'recordedBy'])
            ->where('client_id', $request->client_id)
            ->orderBy('id', 'desc');

        $transactions = $this->paginatedOrFull($request, $query, function ($row) {
            return [
                'id' => $row->id,
                'type' => $row->type,
                'amount' => $row->amount,
                'balance_after' => $row->balance_after,
                'description' => $row->description,
                'ledger_number' => $row->ledger_number,
                'reference_number' => $row->reference_number,
                'case' => $row->case?->case_number,
                'recorded_by' => $row->recordedBy->name ?? 'Unknown user',
                'voided' => ! is_null($row->voided_at),
                'date' => $row->created_at->format('d/m/Y H:i'),
            ];
        });

        return $this->response(true, 'success', [
            'balance' => TrustTransaction::balanceForClient($request->client_id),
            'transactions' => $transactions,
        ], 200);
    }

    public function store(\App\Http\Requests\TrustTransaction\StoreRequest $request): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::CREATE_TRUST_TRANSACTIONS)) {
            abort(403);
        }

        $transaction = app(PostTrustEntry::class)->handle($request->only(['client_id', 'case_id', 'type', 'amount', 'description', 'reference_number']), (int) Auth::id());

        return $this->response(true, 'success', ['balance_after' => $transaction->balance_after], 200);
    }

    /**
     * Void a transaction. This never edits or deletes the original entry —
     * it stamps voided_at/voided_by so the entry is excluded from balance
     * calculations going forward, while the audit trail stays intact.
     * Voiding a deposit is only permitted while the client's balance can
     * still cover it (i.e. the funds haven't already been disbursed).
     */
    public function void(Request $request, $id): Response|JsonResponse
    {
        if (! Auth::user()->checkPermissionTo(ModulePermissions::VOID_TRUST_TRANSACTIONS)) {
            abort(403);
        }

        $balance = app(VoidTrustEntry::class)->handle(TrustTransaction::findOrFail($id), Auth::user());

        return $this->response(true, 'success', [
            'balance' => $balance,
        ], 200);
    }
}
