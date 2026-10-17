<?php

namespace App\Http\Controllers;

use App\Documents\Signatures;
use App\Http\Requests\Sign\DeclineRequest;
use App\Http\Requests\Sign\SignRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/**
 * FUN-14: the signer's side, on the firm's own host. No login: the personal link's secret is the credential, and it
 * reveals only the document the signer was sent.
 */
class SignController extends Controller
{
    public function __construct(private Signatures $signatures) {}

    public function page(string $token)
    {
        $nonce = bin2hex(random_bytes(16));

        return response()->view('signatures.sign', ['nonce' => $nonce, 'token' => $token])->withHeaders([
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]);
    }

    public function show(string $token): JsonResponse
    {
        $o = $this->signatures->open($token);
        abort_if(! $o, 404);
        ['signer' => $s, 'request' => $r] = $o;
        $doc = Document::withoutGlobalScopes()->find($r->document_id);

        return $this->response(true, 'success', [
            'firm' => config('app.name'), 'title' => $r->title, 'message' => $r->message, 'filename' => $doc?->filename,
            'is_pdf' => $doc?->mimetype === 'application/pdf', 'signer' => $s->name, 'state' => $s->state, 'request_state' => $r->state,
            'your_turn' => $this->signatures->isTurn($r, $s), 'expires_at' => $r->expires_at, 'sha256' => $r->document_sha256,
        ], 200);
    }

    /** The exact file sent for signature (opening it is recorded). */
    public function document(string $token)
    {
        $o = $this->signatures->open($token);
        abort_if(! $o || in_array($o['request']->state, ['cancelled'], true), 404);
        $doc = Document::withoutGlobalScopes()->find($o['request']->document_id);
        abort_if(! $doc, 404);
        $this->signatures->viewed($o['signer']);

        return Storage::disk($doc->disk ?: 'local')->response($doc->full_path, $doc->filename, [
            'Content-Type' => $doc->mimetype, 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => ($doc->mimetype === 'application/pdf' ? 'inline' : 'attachment').'; filename="'.addcslashes((string) $doc->filename, '"\\').'"',
        ]);
    }

    public function sign(SignRequest $r, string $token): JsonResponse
    {
        $why = $this->signatures->sign($token, trim($r->validated('name')), $r->validated('signature'), $r->ip(), $r->userAgent(), $r->getSchemeAndHttpHost());

        return $why === null ? $this->response(true, 'Thank you. Your signature has been recorded.', null, 200) : $this->response(false, $why, null, 409);
    }

    public function decline(DeclineRequest $r, string $token): JsonResponse
    {
        $why = $this->signatures->decline($token, $r->validated('reason'), $r->ip());

        return $why === null ? $this->response(true, 'You declined to sign. The firm has been told.', null, 200) : $this->response(false, $why, null, 409);
    }
}
