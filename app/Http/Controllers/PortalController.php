<?php

namespace App\Http\Controllers;

use App\Http\Requests\Portal\LoginRequest;
use App\Http\Requests\Portal\MessageRequest;
use App\Http\Requests\Portal\UploadRequest;
use App\Http\Requests\Portal\VerifyRequest;
use App\Payments\PaymentGatewayException;
use App\Portal\ClientPortal;
use App\Portal\PortalAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * FUN-10: the client portal, on the firm's own host. Sign-in is by a code texted to the client's phone (no staff login
 * is involved); every other endpoint needs the portal session (`portal.session`) and only ever shows that client's
 * own matters, documents shared with them, their messages and their issued invoices.
 */
class PortalController extends Controller
{
    public function __construct(private PortalAuth $auth, private ClientPortal $portal) {}

    public function page()
    {
        $nonce = bin2hex(random_bytes(16));

        return response()->view('portal.page', ['nonce' => $nonce])->withHeaders([
            'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
        ]);
    }

    public function login(LoginRequest $r): JsonResponse
    {
        $this->auth->requestCode($r->validated('phone'));

        // the same answer whether or not the number is a portal client's
        return $this->response(true, 'If this number is registered for the client portal, a sign-in code is on its way by SMS.', null, 200);
    }

    public function verify(VerifyRequest $r): JsonResponse
    {
        $session = $this->auth->verify($r->validated('phone'), $r->validated('code'), $r->ip());
        if ($session === null) {
            return $this->response(false, 'That code is not right or has expired. Ask for a new one.', null, 422);
        }

        return $this->response(true, 'success', $session, 200);
    }

    public function logout(Request $r): JsonResponse
    {
        $this->auth->logout((string) $r->bearerToken());

        return $this->response(true, 'Signed out.', null, 200);
    }

    public function me(Request $r): JsonResponse
    {
        return $this->response(true, 'success', $this->portal->me($this->client($r)), 200);
    }

    public function matters(Request $r): JsonResponse
    {
        return $this->response(true, 'success', $this->portal->matters($this->client($r)), 200);
    }

    public function matter(Request $r, int $case): JsonResponse
    {
        return $this->response(true, 'success', $this->portal->matter($this->client($r), $case), 200);
    }

    public function upload(UploadRequest $r, int $case): JsonResponse
    {
        return $this->response(true, 'Document sent to the firm.', $this->portal->upload($this->client($r), $case, $r->file('file'), $r->validated('title')), 201);
    }

    public function message(MessageRequest $r, int $case): JsonResponse
    {
        return $this->response(true, 'Message sent.', $this->portal->sendMessage($this->client($r), $case, $r->validated('body')), 201);
    }

    public function download(Request $r, int $share)
    {
        $doc = $this->portal->download($this->client($r), $share);

        return Storage::disk($doc->disk ?: 'local')->download($doc->full_path, $doc->filename, ['Cache-Control' => 'no-store']);
    }

    public function invoices(Request $r): JsonResponse
    {
        return $this->response(true, 'success', $this->portal->invoices($this->client($r)), 200);
    }

    public function pay(Request $r, int $invoice): JsonResponse
    {
        try {
            $token = $this->portal->payLink($this->client($r), $invoice);
        } catch (PaymentGatewayException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }

        return $this->response(true, 'success', ['url' => '/pay/'.$token], 200);
    }

    private function client(Request $r): int
    {
        return (int) $r->attributes->get('portal_client_id');
    }
}
