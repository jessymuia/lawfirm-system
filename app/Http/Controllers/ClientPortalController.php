<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClientPortal\AccessRequest;
use App\Http\Requests\ClientPortal\ShareRequest;
use App\Http\Requests\Portal\MessageRequest;
use App\Jobs\SendSmsJob;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Document;
use App\Portal\ClientPortal;
use App\Portal\PortalAuth;
use App\Security\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * FUN-10: the firm's side of the client portal: who may use it, which of a matter's documents the client can see, and
 * the message thread with the client. Matters are read through the model, so matter access and ethical walls apply.
 */
class ClientPortalController extends Controller
{
    public function __construct(private ClientPortal $portal, private PortalAuth $auth) {}

    public function access(int $client): JsonResponse
    {
        return $this->response(true, 'success', $this->accessOf(Client::findOrFail($client)), 200);
    }

    public function setAccess(AccessRequest $r, int $client): JsonResponse
    {
        $c = Client::findOrFail($client);
        $on = $r->boolean('enabled');
        $existing = DB::table('portal_accounts')->where('client_id', $c->id)->first();
        DB::table('portal_accounts')->updateOrInsert(['client_id' => $c->id], array_filter([
            'enabled' => $on, 'updated_at' => now(), 'created_at' => $existing ? null : now(),
            'invited_by' => $on ? Auth::id() : null, 'invited_at' => $on ? now() : null,
        ], fn ($v) => $v !== null));
        if (! $on) {
            $this->auth->revokeAll((int) $c->id);
        } elseif ($r->boolean('send_invite', true) && $c->phone_number) {
            SendSmsJob::dispatch((string) $c->phone_number, config('app.name').' has opened a secure client portal for you: see your matters, documents and invoices at '
                .$r->getSchemeAndHttpHost().'/portal . Sign in with this phone number.');
        }
        SecurityPolicy::audit($on ? 'portal_access_granted' : 'portal_access_withdrawn', 'client', (int) $c->id, [], ['enabled' => $on]);

        return $this->response(true, $on ? 'Portal access given.' : 'Portal access withdrawn; open sessions were ended.', $this->accessOf($c), 200);
    }

    /** The matter's portal tab: the client's access, documents both ways, and the thread (the client's messages become read). */
    public function matter(int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);
        DB::table('portal_messages')->where('case_id', $m->id)->where('direction', 'from_client')->whereNull('read_at')->update(['read_at' => now()]);

        return $this->response(true, 'success', [
            'client' => ['id' => (int) $m->client_id, 'name' => $m->client?->name] + $this->accessOf($m->client),
            'documents' => $this->portal->documents((int) $m->id),
            'messages' => $this->portal->messages((int) $m->id),
        ], 200);
    }

    public function share(ShareRequest $r, int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);
        $doc = Document::where('case_id', $m->id)->findOrFail((int) $r->validated('document_id'));
        $live = DB::table('portal_documents')->where('document_id', $doc->id)->where('direction', 'to_client')->whereNull('revoked_at')->first();
        if (! $live) {
            DB::table('portal_documents')->insert([
                'document_id' => $doc->id, 'case_id' => $m->id, 'direction' => 'to_client', 'shared_by' => Auth::id(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            SecurityPolicy::audit('portal_document_shared', 'document', (int) $doc->id, [], ['case_id' => $m->id, 'client_id' => $m->client_id]);
            if ($r->boolean('notify') && $m->client?->phone_number && $this->enabled((int) $m->client_id)) {
                SendSmsJob::dispatch((string) $m->client->phone_number, config('app.name').' shared a document with you on matter '.$m->case_number.'. See it in your client portal.');
            }
        }

        return $this->response(true, 'Shared with the client.', $this->portal->documents((int) $m->id), 201);
    }

    public function unshare(int $case, int $share): JsonResponse
    {
        $m = Cases::findOrFail($case);
        $n = DB::table('portal_documents')->where('id', $share)->where('case_id', $m->id)->where('direction', 'to_client')->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
        abort_if($n === 0, 404);
        SecurityPolicy::audit('portal_document_unshared', 'portal_document', $share, [], ['case_id' => $m->id]);

        return $this->response(true, 'No longer shared.', $this->portal->documents((int) $m->id), 200);
    }

    public function message(MessageRequest $r, int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);
        DB::table('portal_messages')->insert([
            'case_id' => $m->id, 'direction' => 'to_client', 'user_id' => Auth::id(), 'body' => $r->validated('body'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // the message itself stays in the portal; the SMS only says there is one
        if ($m->client?->phone_number && $this->enabled((int) $m->client_id)) {
            SendSmsJob::dispatch((string) $m->client->phone_number, 'You have a new message from '.config('app.name').' about matter '.$m->case_number.'. Read it in your client portal.');
        }

        return $this->response(true, 'Message sent.', $this->portal->messages((int) $m->id), 201);
    }

    /** Matters with client messages nobody has read yet, for the staff inbox badge. */
    public function unread(Request $r): JsonResponse
    {
        $visible = Cases::query()->select('id');   // matter access applies
        $rows = DB::table('portal_messages')->join('cases', 'cases.id', '=', 'portal_messages.case_id')
            ->leftJoin('clients', 'clients.id', '=', 'cases.client_id')
            ->where('portal_messages.direction', 'from_client')->whereNull('portal_messages.read_at')
            ->whereIn('portal_messages.case_id', $visible)
            ->groupBy('portal_messages.case_id', 'cases.case_number', 'clients.name')
            ->selectRaw('portal_messages.case_id, cases.case_number, clients.name as client, count(*) as unread, max(portal_messages.created_at) as latest')
            ->orderByDesc('latest')->limit(50)->get();

        return $this->response(true, 'success', $rows, 200);
    }

    private function accessOf(?Client $c): array
    {
        $a = $c ? DB::table('portal_accounts')->where('client_id', $c->id)->first() : null;

        return ['portal_enabled' => (bool) ($a->enabled ?? false), 'invited_at' => $a->invited_at ?? null, 'last_login_at' => $a->last_login_at ?? null,
            'has_phone' => (bool) $c?->phone_number];
    }

    private function enabled(int $clientId): bool
    {
        return DB::table('portal_accounts')->where('client_id', $clientId)->where('enabled', true)->exists();
    }
}
