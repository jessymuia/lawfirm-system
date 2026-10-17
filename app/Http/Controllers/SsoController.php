<?php

namespace App\Http\Controllers;

use App\Http\Requests\Sso\UpdateRequest;
use App\Security\SecurityPolicy;
use App\Sso\SsoException;
use App\Sso\SsoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** SEC-12: OpenID Connect sign-in for a firm (public start/callback) and its configuration (manage-security-settings, plan feature `sso`). */
class SsoController extends Controller
{
    public function __construct(private SsoService $sso) {}

    private function redirectUri(Request $r): string
    {
        return $r->getSchemeAndHttpHost().'/api/v1/sso/callback';
    }

    /** Public: lets the sign-in page decide whether to offer "Sign in with SSO" and whether passwords are still accepted. */
    public function publicConfig(): JsonResponse
    {
        $p = $this->sso->provider();

        return $this->response(true, 'success', ['available' => $this->sso->available(), 'enforce' => $p ? (bool) $p->enforce : false], 200);
    }

    public function start(Request $r): RedirectResponse|JsonResponse
    {
        if (! $this->sso->available()) {
            return $this->response(false, 'Single sign-on is not available for this firm.', null, 404);
        }
        try {
            return redirect()->away($this->sso->start($this->redirectUri($r)));
        } catch (SsoException $e) {
            Log::warning('sso.start failed: '.$e->getMessage());

            return redirect(config('sso.failure_redirect'));
        }
    }

    /** Every failure is the same redirect: the person is told nothing about why (the reason goes to the log and the firm's audit trail). */
    private function fail(string $reason): RedirectResponse
    {
        Log::warning('sso.callback denied: '.$reason);
        DB::table('audits')->insert([
            'user_type' => null, 'user_id' => null, 'event' => 'sso_login_denied', 'auditable_type' => 'sso', 'auditable_id' => 0,
            'old_values' => '[]', 'new_values' => json_encode(['reason' => mb_substr($reason, 0, 200)]),
            'url' => request()->fullUrl(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            'tags' => 'security', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return redirect(config('sso.failure_redirect'));
    }

    private function signIn(Request $r, $user, object $provider): RedirectResponse
    {
        Auth::guard('web')->login($user);
        $r->session()->regenerate();
        $r->session()->put('sso_idp_mfa', (bool) $provider->idp_enforces_mfa);
        SecurityPolicy::audit('sso_login', 'user', $user->id, [], ['issuer' => $provider->issuer, 'protocol' => $provider->protocol ?? 'oidc']);

        return redirect(config('sso.success_redirect'));
    }

    public function callback(Request $r): RedirectResponse
    {
        if (! $this->sso->available()) {
            return $this->fail('sso not available');
        }
        if ($r->filled('error') || ! $r->filled('code') || ! $r->filled('state')) {
            return $this->fail('provider error or missing parameters: '.$r->input('error', 'none'));
        }
        try {
            [$user, $provider] = $this->sso->complete((string) $r->input('code'), (string) $r->input('state'), $this->redirectUri($r));
        } catch (SsoException $e) {
            return $this->fail($e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('unexpected: '.get_class($e));
        }

        return $this->signIn($r, $user, $provider);
    }

    /** SAML: the identity provider posts the signed response here. The request we sent is single use and bound to the response. */
    public function samlAcs(Request $r): RedirectResponse
    {
        if (! $this->sso->available()) {
            return $this->fail('sso not available');
        }
        if (! $r->filled('SAMLResponse') || ! $r->filled('RelayState')) {
            return $this->fail('saml: missing parameters');
        }
        try {
            [$user, $provider] = $this->sso->completeSaml((string) $r->input('SAMLResponse'), (string) $r->input('RelayState'), $r->getSchemeAndHttpHost());
        } catch (SsoException $e) {
            return $this->fail('saml: '.$e->getMessage());
        } catch (\Throwable $e) {
            return $this->fail('saml unexpected: '.get_class($e));
        }

        return $this->signIn($r, $user, $provider);
    }

    /** What the firm gives its identity provider: entity ID and sign-in (ACS) URL. Nothing private. */
    public function samlMetadata(Request $r): Response
    {
        return response(SsoService::samlMetadata($r->getSchemeAndHttpHost()), 200, ['Content-Type' => 'application/samlmetadata+xml']);
    }

    // ---- configuration -----------------------------------------------------------------------------------------------

    public function show(): JsonResponse
    {
        return $this->response(true, 'success', SsoService::view($this->sso->provider(false)), 200);
    }

    public function update(UpdateRequest $r): JsonResponse
    {
        $d = $r->validated();
        $before = SsoService::view($this->sso->provider(false));
        try {
            $p = $this->sso->configure($d, Auth::id());
        } catch (SsoException $e) {
            return $this->response(false, $e->getMessage(), null, 422);
        }
        // An enforced SSO needs a way back in: the person turning it on must be a security administrator themselves.
        $after = SsoService::view($p);
        SecurityPolicy::audit('sso_configured', 'sso', (int) $p->id, $before ?? [], $after);

        return $this->response(true, 'success', $after, 200);
    }

    public function destroy(): JsonResponse
    {
        $before = SsoService::view($this->sso->provider(false));
        DB::table('sso_providers')->delete();
        DB::table('sso_links')->delete();
        SecurityPolicy::audit('sso_removed', 'sso', 0, $before ?? [], []);

        return $this->response(true, 'Single sign-on was removed. Everyone signs in with their password again.', null, 200);
    }
}
