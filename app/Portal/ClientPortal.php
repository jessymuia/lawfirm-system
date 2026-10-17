<?php

namespace App\Portal;

use App\Actions\Documents\StoreDocument;
use App\Models\Document;
use App\Models\Invoice;
use App\Payments\OnlinePayments;
use App\Security\SecurityPolicy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FUN-10: what a signed-in client sees and does in the portal. Every read starts from the client's own id, never from an
 * id the browser sent: a matter, document, message or invoice that is not the client's answers 404, the same as one that
 * does not exist. The firm's internal records (notes, hearing outcomes, time, documents not shared) never appear.
 */
class ClientPortal
{
    public function __construct(private OnlinePayments $payments) {}

    public function me(int $clientId): array
    {
        $c = DB::table('clients')->where('id', $clientId)->first(['id', 'name', 'phone_number']);

        return ['name' => $c->name, 'phone' => $this->maskPhone((string) $c->phone_number), 'firm' => config('app.name')];
    }

    public function matters(int $clientId): array
    {
        $next = DB::table('hearings')->selectRaw('case_id, min(hearing_date) as next_hearing')
            ->whereNull('deleted_at')->where('hearing_date', '>=', now()->toDateString())->groupBy('case_id');
        $unread = DB::table('portal_messages')->selectRaw('case_id, count(*) as unread')
            ->where('direction', 'to_client')->whereNull('read_at')->groupBy('case_id');

        return DB::table('cases')
            ->leftJoin('users', 'users.id', '=', 'cases.assigned_to')
            ->leftJoinSub($next, 'nh', 'nh.case_id', '=', 'cases.id')
            ->leftJoinSub($unread, 'um', 'um.case_id', '=', 'cases.id')
            ->where('cases.client_id', $clientId)->whereNull('cases.deleted_at')
            ->orderByRaw("case when cases.lifecycle_status = 'closed' then 1 else 0 end")->orderByDesc('cases.start_date')
            ->get(['cases.id', 'cases.case_number', 'cases.description', 'cases.case_type', 'cases.lifecycle_status', 'cases.start_date',
                'users.name as lawyer', 'nh.next_hearing', 'um.unread'])
            ->map(fn ($m) => [
                'id' => (int) $m->id, 'case_number' => $m->case_number, 'description' => $m->description, 'case_type' => $m->case_type,
                'status' => $m->lifecycle_status ?: 'open', 'start_date' => $m->start_date, 'lawyer' => $m->lawyer,
                'next_hearing' => $m->next_hearing, 'unread_messages' => (int) ($m->unread ?? 0),
            ])->all();
    }

    /** One matter: hearings (dates and courts only), shared documents both ways, and the message thread. */
    public function matter(int $clientId, int $caseId): array
    {
        $m = collect($this->matters($clientId))->firstWhere('id', $caseId) ?? throw new NotFoundHttpException;

        $hearings = DB::table('hearings')->leftJoin('courts', 'courts.id', '=', 'hearings.court_id')
            ->where('hearings.case_id', $caseId)->whereNull('hearings.deleted_at')->orderBy('hearings.hearing_date')
            ->get(['hearings.hearing_date as date', 'courts.name as court'])
            ->map(fn ($h) => ['date' => $h->date, 'court' => $h->court, 'upcoming' => $h->date >= now()->toDateString()])->all();

        // the client has now seen the firm's messages
        DB::table('portal_messages')->where('case_id', $caseId)->where('direction', 'to_client')->whereNull('read_at')->update(['read_at' => now()]);

        return $m + ['hearings' => $hearings, 'documents' => $this->documents($caseId), 'messages' => $this->messages($caseId)];
    }

    public function documents(int $caseId): array
    {
        return DB::table('portal_documents')->join('documents', 'documents.id', '=', 'portal_documents.document_id')
            ->leftJoin('users', 'users.id', '=', 'portal_documents.shared_by')
            ->where('portal_documents.case_id', $caseId)->whereNull('portal_documents.revoked_at')->whereNull('documents.deleted_at')
            ->orderByDesc('portal_documents.id')
            ->get(['portal_documents.id', 'portal_documents.document_id', 'portal_documents.direction', 'portal_documents.created_at', 'portal_documents.first_viewed_at',
                'documents.title', 'documents.filename', 'documents.filesize', 'users.name as shared_by'])
            ->map(fn ($d) => [
                'id' => (int) $d->id, 'document_id' => (int) $d->document_id, 'title' => $d->title, 'filename' => $d->filename, 'size' => (int) $d->filesize,
                'direction' => $d->direction, 'shared_at' => $d->created_at, 'shared_by' => $d->shared_by, 'viewed_at' => $d->first_viewed_at,
            ])->all();
    }

    public function messages(int $caseId): array
    {
        return DB::table('portal_messages')->leftJoin('users', 'users.id', '=', 'portal_messages.user_id')
            ->where('portal_messages.case_id', $caseId)->orderBy('portal_messages.id')
            ->get(['portal_messages.id', 'portal_messages.direction', 'portal_messages.body', 'portal_messages.created_at', 'portal_messages.read_at', 'users.name as sender'])
            ->map(fn ($m) => [
                'id' => (int) $m->id, 'direction' => $m->direction, 'body' => $m->body, 'sent_at' => $m->created_at,
                'read_at' => $m->read_at, 'sender' => $m->direction === 'to_client' ? $m->sender : null,
            ])->all();
    }

    /** A document shared with (or sent by) this client, to stream back. Downloads are audited. */
    public function download(int $clientId, int $shareId): Document
    {
        $share = DB::table('portal_documents')->join('cases', 'cases.id', '=', 'portal_documents.case_id')
            ->where('portal_documents.id', $shareId)->where('cases.client_id', $clientId)->whereNull('cases.deleted_at')
            ->whereNull('portal_documents.revoked_at')->first(['portal_documents.*']) ?? throw new NotFoundHttpException;
        $doc = Document::withoutGlobalScopes()->whereNull('deleted_at')->find($share->document_id) ?? throw new NotFoundHttpException;

        if ($share->direction === 'to_client' && $share->first_viewed_at === null) {
            DB::table('portal_documents')->where('id', $share->id)->update(['first_viewed_at' => now(), 'updated_at' => now()]);
        }
        SecurityPolicy::audit('portal_document_downloaded', 'document', (int) $doc->id, [], ['client_id' => $clientId, 'share_id' => $shareId]);

        return $doc;
    }

    /** The client sends the firm a document on one of their matters. It is stored like any other matter document. */
    public function upload(int $clientId, int $caseId, UploadedFile $file, string $title): array
    {
        $this->matterOf($clientId, $caseId);
        return DB::transaction(function () use ($caseId, $file, $title, $clientId) {
            $doc = app(StoreDocument::class)->handle($caseId, $file, ['title' => $title], null);
            $id = DB::table('portal_documents')->insertGetId([
                'document_id' => $doc->id, 'case_id' => $caseId, 'direction' => 'from_client', 'created_at' => now(), 'updated_at' => now(),
            ]);
            SecurityPolicy::audit('portal_document_uploaded', 'document', (int) $doc->id, [], ['client_id' => $clientId, 'case_id' => $caseId]);

            return collect($this->documents($caseId))->firstWhere('id', $id);
        });
    }

    public function sendMessage(int $clientId, int $caseId, string $body): array
    {
        $this->matterOf($clientId, $caseId);
        $id = DB::table('portal_messages')->insertGetId([
            'case_id' => $caseId, 'direction' => 'from_client', 'body' => $body, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return collect($this->messages($caseId))->firstWhere('id', $id);
    }

    /** The client's issued invoices, newest first, with what is still owed. Drafts and voided invoices are the firm's business. */
    public function invoices(int $clientId): array
    {
        return DB::table('invoices')->leftJoin('cases', 'cases.id', '=', 'invoices.case_id')
            ->where('invoices.client_id', $clientId)->where('invoices.workflow_status', 'submitted')->whereNull('invoices.deleted_at')
            ->orderByDesc('invoices.invoice_date')->orderByDesc('invoices.id')
            ->get(['invoices.id', 'invoices.invoice_number', 'invoices.invoice_date', 'invoices.invoice_due_date', 'invoices.currency',
                'invoices.total_amount', 'invoices.amount_paid', 'cases.case_number'])
            ->map(function ($i) {
                $due = round((float) $i->total_amount - (float) $i->amount_paid, 2);

                return [
                    'id' => (int) $i->id, 'invoice_number' => $i->invoice_number, 'case_number' => $i->case_number, 'date' => $i->invoice_date,
                    'due_date' => $i->invoice_due_date, 'currency' => $i->currency ?: 'KES', 'total' => (float) $i->total_amount,
                    'paid' => (float) $i->amount_paid, 'outstanding' => max(0, $due),
                    'overdue' => $due > 0 && $i->invoice_due_date !== null && $i->invoice_due_date < now()->toDateString(),
                ];
            })->all();
    }

    /** A short-lived payment link (FUN-11) for one of the client's unpaid invoices. */
    public function payLink(int $clientId, int $invoiceId): string
    {
        $row = collect($this->invoices($clientId))->firstWhere('id', $invoiceId) ?? throw new NotFoundHttpException;
        if ($row['outstanding'] <= 0) {
            throw new \App\Payments\PaymentGatewayException('This invoice is already paid.');
        }

        return $this->payments->createLink(Invoice::withoutGlobalScopes()->findOrFail($invoiceId), null, 1);
    }

    private function matterOf(int $clientId, int $caseId): object
    {
        return DB::table('cases')->where('id', $caseId)->where('client_id', $clientId)->whereNull('deleted_at')->first(['id'])
            ?? throw new NotFoundHttpException;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) > 3 ? str_repeat('•', strlen($digits) - 3).substr($digits, -3) : $phone;
    }
}
