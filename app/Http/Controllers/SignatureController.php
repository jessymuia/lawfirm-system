<?php

namespace App\Http\Controllers;

use App\Documents\Signatures;
use App\Http\Requests\Signature\StoreRequest;
use App\Models\Cases;
use App\Models\Document;
use App\Security\SecurityPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** FUN-14: the firm's side of e-signature: send a matter document, follow it, remind, cancel. */
class SignatureController extends Controller
{
    public function __construct(private Signatures $signatures) {}

    public function index(int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);   // matter access applies
        $requests = DB::table('signature_requests')->leftJoin('documents', 'documents.id', '=', 'signature_requests.document_id')
            ->where('signature_requests.case_id', $m->id)->orderByDesc('signature_requests.id')
            ->get(['signature_requests.id', 'signature_requests.title', 'signature_requests.state', 'signature_requests.in_order', 'signature_requests.expires_at',
                'signature_requests.completed_at', 'signature_requests.certificate_document_id', 'signature_requests.document_id', 'documents.filename', 'signature_requests.created_at']);
        $signers = DB::table('signature_signers')->whereIn('signature_request_id', $requests->pluck('id'))->orderBy('position')
            ->get(['id', 'signature_request_id', 'position', 'name', 'email', 'phone', 'state', 'viewed_at', 'signed_at', 'decline_reason'])->groupBy('signature_request_id');

        return $this->response(true, 'success', $requests->map(fn ($r) => (array) $r + ['signers' => $signers->get($r->id, collect())->values()])->values(), 200);
    }

    public function store(StoreRequest $r, int $case): JsonResponse
    {
        $m = Cases::findOrFail($case);
        $doc = Document::where('case_id', $m->id)->findOrFail((int) $r->validated('document_id'));
        try {
            $id = $this->signatures->create($doc, $r->validated('title'), $r->validated('message'), $r->validated('signers'),
                $r->boolean('in_order'), (int) ($r->validated('days') ?? Signatures::LINK_DAYS), (int) Auth::id(), $r->getSchemeAndHttpHost());
        } catch (\RuntimeException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }

        return $this->response(true, 'Sent for signature.', ['id' => $id], 201);
    }

    /** Sends the people who have not signed yet (and whose turn it is) a fresh link. */
    public function remind(Request $r, int $case, int $request): JsonResponse
    {
        $req = $this->pending($case, $request);
        $n = 0;
        foreach (DB::table('signature_signers')->where('signature_request_id', $req->id)->whereNotIn('state', ['signed', 'declined'])->orderBy('position')->get() as $s) {
            if ($this->signatures->isTurn($req, $s)) {
                $this->signatures->notify((int) $s->id, $r->getSchemeAndHttpHost());
                $n++;
            }
        }
        SecurityPolicy::audit('signature_reminded', 'signature_request', (int) $req->id, [], ['signers' => $n]);

        return $this->response(true, $n === 1 ? 'Reminder sent.' : "{$n} reminders sent.", null, 200);
    }

    public function cancel(int $case, int $request): JsonResponse
    {
        $req = $this->pending($case, $request);
        DB::table('signature_requests')->where('id', $req->id)->update(['state' => 'cancelled', 'updated_at' => now()]);
        SecurityPolicy::audit('signature_cancelled', 'signature_request', (int) $req->id, [], []);

        return $this->response(true, 'Signature request cancelled; its links no longer work.', null, 200);
    }

    private function pending(int $case, int $request): object
    {
        $m = Cases::findOrFail($case);
        $req = DB::table('signature_requests')->where('case_id', $m->id)->where('id', $request)->first();
        abort_if(! $req, 404);
        abort_if($req->state !== 'pending', 409, 'This request is no longer waiting for signatures.');

        return $req;
    }
}
