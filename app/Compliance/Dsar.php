<?php

namespace App\Compliance;

use App\Models\Client;
use App\Security\SecurityPolicy;
use App\Tenancy\FirmStorage;
use App\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * SEC-13: data-subject requests (access and erasure) for a client.
 *
 * Flow: record the request (30-day clock) -> verify the requester's identity (method + note + who, recorded) -> only then
 * produce the access export or perform erasure. A legal hold overrides erasure: the request is marked `blocked`, nothing is
 * touched, and the reason is recorded.
 *
 * Erasure is anonymisation, never row deletion: the client's personal fields are replaced and redacted inside audit history,
 * while records a firm must keep (invoices, payments, the trust ledger, matter files inside their retention period) stay and
 * are listed in the outcome with the reason. Lookups of the client go through the normal scoped model, so an ethical wall
 * hides a client from the person handling the request exactly as it does everywhere else.
 */
class Dsar
{
    public const TYPES = ['access', 'erasure'];

    public const METHODS = ['id_document', 'known_contact', 'in_person', 'other'];

    public function __construct(private LegalHolds $holds, private ClientAnonymiser $anonymiser) {}

    public function create(string $type, int $clientId, string $name, ?string $email, ?string $received, ?int $actor): object
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new ComplianceException('Type must be "access" or "erasure".');
        }
        Client::whereKey($clientId)->first() ?? throw new ComplianceException('No such client.', 404);
        $receivedAt = $received ? Carbon::parse($received)->startOfDay() : now()->startOfDay();
        $id = DB::table('dsar_requests')->insertGetId([
            'type' => $type, 'client_id' => $clientId, 'requester_name' => $name, 'requester_email' => $email,
            'status' => 'pending_verification', 'received_at' => $receivedAt->toDateString(),
            'due_at' => $receivedAt->copy()->addDays((int) config('compliance.dsar_days'))->toDateString(),
            'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('dsar_received', 'dsar', $id, [], ['type' => $type, 'client_id' => $clientId]);

        return $this->find($id);
    }

    public function find(int $id): object
    {
        $r = DB::table('dsar_requests')->where('id', $id)->first() ?? throw new ComplianceException('No such request.', 404);
        // a request about a client the handler is walled off from is invisible to them
        Client::whereKey($r->client_id)->exists() || throw new ComplianceException('No such request.', 404);

        return $r;
    }

    public function verify(int $id, string $method, string $note, ?int $actor): object
    {
        $r = $this->find($id);
        if ($r->status !== 'pending_verification') {
            throw new ComplianceException('Only a request awaiting verification can be verified.', 409);
        }
        if (! in_array($method, self::METHODS, true) || trim($note) === '') {
            throw new ComplianceException('Say how the requester\'s identity was verified (method and a note).');
        }
        DB::table('dsar_requests')->where('id', $id)->update([
            'status' => 'verified', 'verification_method' => $method, 'verification_note' => $note,
            'verified_by' => $actor, 'verified_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('dsar_verified', 'dsar', $id, [], ['method' => $method]);

        return $this->find($id);
    }

    public function reject(int $id, string $reason, ?int $actor): object
    {
        $r = $this->find($id);
        if (in_array($r->status, ['completed', 'rejected'], true)) {
            throw new ComplianceException('That request is already closed.', 409);
        }
        if (trim($reason) === '') {
            throw new ComplianceException('A reason is required.');
        }
        DB::table('dsar_requests')->where('id', $id)->update(['status' => 'rejected', 'handled_by' => $actor, 'completed_at' => now(), 'outcome' => json_encode(['rejected' => $reason]), 'updated_at' => now()]);
        SecurityPolicy::audit('dsar_rejected', 'dsar', $id, [], ['reason' => $reason]);

        return $this->find($id);
    }

    /** Build and store the access export (JSON). Needs a verified access request. @return array{request:object,path:string} */
    public function export(int $id, ?int $actor): array
    {
        $r = $this->find($id);
        if ($r->type !== 'access') {
            throw new ComplianceException('That is not an access request.', 409);
        }
        if (! in_array($r->status, ['verified', 'completed'], true)) {
            throw new ComplianceException('Verify the requester\'s identity before exporting data.', 409);
        }
        $client = Client::findOrFail($r->client_id);
        $pick = fn (string $table, array $cols) => array_values(array_intersect($cols, Schema::getColumnListing($table)));

        $cases = DB::table('cases')->where('client_id', $client->id)->select($pick('cases', ['id', 'case_number', 'case_type', 'start_date', 'end_date', 'lifecycle_status']))->get();
        $caseIds = $cases->pluck('id');
        $invoices = DB::table('invoices')->where('client_id', $client->id)->select($pick('invoices', ['id', 'invoice_number', 'invoice_date', 'invoice_due_date', 'currency', 'total', 'total_amount', 'amount_paid', 'payment_status', 'workflow_status']))->get();
        $payments = DB::table('payments')->whereIn('invoice_id', $invoices->pluck('id'))->select($pick('payments', ['id', 'invoice_id', 'receipt_number', 'amount', 'payment_date', 'method', 'deleted_at']))->get();
        $trust = DB::table('trust_transactions')->where('client_id', $client->id)->select($pick('trust_transactions', ['id', 'case_id', 'type', 'amount', 'description', 'created_at', 'voided_at']))->get();
        $hearings = DB::table('hearings')->whereIn('case_id', $caseIds)->select($pick('hearings', ['id', 'case_id', 'hearing_date']))->get();
        $documents = DB::table('documents')->whereIn('case_id', $caseIds)->select($pick('documents', ['id', 'case_id', 'title', 'filename', 'mimetype', 'filesize', 'created_at', 'deleted_at']))->get();

        $data = [
            'generated_at' => now()->toIso8601String(),
            'request' => ['id' => $r->id, 'requester' => $r->requester_name, 'received_at' => $r->received_at, 'verified_by_method' => $r->verification_method],
            'subject' => $client->only(config('compliance.client_pii')) + ['id' => $client->id, 'created_at' => (string) $client->created_at],
            'matters' => $cases, 'invoices' => $invoices, 'payments' => $payments, 'trust_ledger_entries' => $trust,
            'hearing_dates' => $hearings, 'document_index' => $documents,
            'withheld_pending_review' => [
                'Legal advice, lawyer notes and work product (matter descriptions, hearing notes and outcomes) — privileged or the firm\'s own; review before release.',
                'Names and details of opposing parties and other third parties.',
                'The contents of documents (listed in document_index; release after review).',
            ],
        ];
        $prefix = app(TenantContext::class)->storagePrefix('dsar');
        $path = "{$prefix}/request-{$id}.json";
        Storage::disk(FirmStorage::disk())->put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        DB::table('dsar_requests')->where('id', $id)->update([
            'status' => 'completed', 'handled_by' => $actor, 'completed_at' => $r->completed_at ?? now(),
            'outcome' => json_encode(['export' => $path, 'rows' => ['matters' => $cases->count(), 'invoices' => $invoices->count(), 'payments' => $payments->count(), 'trust' => $trust->count(), 'documents' => $documents->count()]]),
            'updated_at' => now(),
        ]);
        SecurityPolicy::audit('dsar_access_exported', 'dsar', $id, [], ['client_id' => $client->id]);

        return ['request' => $this->find($id), 'path' => $path];
    }

    public function read(int $id): string
    {
        $r = $this->find($id);
        $out = json_decode((string) $r->outcome, true);
        $path = $out['export'] ?? null;
        $disk = Storage::disk(FirmStorage::disk());
        if (! $path || ! $disk->exists($path)) {
            throw new ComplianceException('No export has been produced for that request.', 404);
        }

        return $disk->get($path);
    }

    /** Erase = anonymise the client's personal data. Blocked entirely by a legal hold. */
    public function erase(int $id, ?int $actor): object
    {
        $r = $this->find($id);
        if ($r->type !== 'erasure') {
            throw new ComplianceException('That is not an erasure request.', 409);
        }
        if ($r->status !== 'verified' && $r->status !== 'blocked') {
            throw new ComplianceException($r->status === 'pending_verification' ? 'Verify the requester\'s identity first.' : 'That request is closed.', 409);
        }
        $client = Client::findOrFail($r->client_id);

        if ($this->holds->clientOrMattersHeld((int) $client->id)) {
            DB::table('dsar_requests')->where('id', $id)->update(['status' => 'blocked', 'handled_by' => $actor, 'outcome' => json_encode(['blocked' => 'legal_hold']), 'updated_at' => now()]);
            SecurityPolicy::audit('dsar_erasure_blocked', 'dsar', $id, [], ['reason' => 'legal_hold']);

            return $this->find($id);
        }

        $pii = (array) config('compliance.client_pii');
        $redacted = $this->anonymiser->anonymise($client);   // shared with retention of former clients' data

        $caseIds = DB::table('cases')->where('client_id', $client->id)->pluck('id');
        $outcome = [
            'anonymised_fields' => $pii, 'audit_rows_redacted' => $redacted,
            'retained' => [
                'invoices_and_payments' => DB::table('invoices')->where('client_id', $client->id)->count(),
                'trust_ledger_entries' => DB::table('trust_transactions')->where('client_id', $client->id)->count(),
                'matters' => $caseIds->count(),
                'documents' => DB::table('documents')->whereIn('case_id', $caseIds)->count(),
            ],
            'retained_because' => 'Financial records are append-only and kept for accounting/professional duty; matters and documents are disposed of under the retention schedule, not by erasure.',
        ];
        DB::table('dsar_requests')->where('id', $id)->update(['status' => 'completed', 'handled_by' => $actor, 'completed_at' => now(), 'outcome' => json_encode($outcome), 'updated_at' => now()]);
        SecurityPolicy::audit('dsar_erased', 'dsar', $id, [], ['client_id' => $client->id, 'audit_rows_redacted' => $redacted]);

        return $this->find($id);
    }
}
