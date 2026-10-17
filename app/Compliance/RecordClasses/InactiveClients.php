<?php

namespace App\Compliance\RecordClasses;

use App\Compliance\ClientAnonymiser;
use App\Compliance\LegalHolds;
use App\Compliance\LegalHoldException;
use App\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * SEC-13: personal data of former clients. A client is due once every one of its matters was closed or settled on or
 * before the cutoff (or it never had a matter and was added before it). Disposal anonymises the client exactly as a
 * DSAR erasure does (ClientAnonymiser); its invoices, payments and trust entries are kept, being append-only records.
 */
class InactiveClients implements RecordClass
{
    use ClosedMatters;

    public function __construct(private LegalHolds $holds, private ClientAnonymiser $anonymiser) {}

    public function type(): string
    {
        return 'client';
    }

    public function due(string $cutoff, array $heldCaseIds): array
    {
        $stillActive = DB::table('cases')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNotIn('lifecycle_status', ['closed', 'settled'])->orWhereNull('end_date')->orWhere('end_date', '>', $cutoff))
            ->select('client_id');
        $base = DB::table('clients')->whereNull('deleted_at')->where('created_at', '<=', $cutoff.' 23:59:59')
            ->whereNotIn('id', $stillActive)->whereNotIn('id', $this->disposed());
        $heldClients = DB::table('cases')->whereIn('id', $heldCaseIds)->select('client_id');

        return ['held' => (clone $base)->where(fn ($q) => $q->whereIn('id', $this->holds->heldClientIds())->orWhereIn('id', $heldClients))->count(),
            'due' => (clone $base)->whereNotIn('id', $this->holds->heldClientIds())->whereNotIn('id', $heldClients)->orderBy('id')->pluck('id')];
    }

    public function dispose(int $id, int $years, ?int $actor): array
    {
        if ($this->holds->clientOrMattersHeld($id)) {
            throw new LegalHoldException('held');
        }
        $client = Client::withoutGlobalScopes()->findOrFail($id);
        $redacted = DB::transaction(function () use ($client, $years, $actor) {
            $n = $this->anonymiser->anonymise($client);
            DB::table('retention_disposals')->insert(['record_type' => $this->type(), 'record_id' => $client->id, 'case_id' => null,
                'record_class' => 'inactive_clients', 'retain_years' => $years, 'file_deleted' => false, 'disposed_by' => $actor,
                'disposed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $n;
        });

        return ['anonymised' => true, 'audit_rows_redacted' => $redacted];
    }
}
