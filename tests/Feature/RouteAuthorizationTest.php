<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthorizeRoute;
use App\Http\Middleware\RequireAuthzDeclaration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * ENG-11: deny-by-default authorization. Every guarded route declares authz:permission, authz:policy or
 * authz:authenticated; there is no mode that leaves the decision to the controller.
 */
class RouteAuthorizationTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private const PUBLIC_ROUTES = [
        'POST api/v1/login',
        'GET api/v1/branding', 'GET api/v1/branding/logo', // UI-13: the firm's public face for the sign-in page; name, colour and logo only; throttled
        'POST api/v1/password-recovery',
        'POST api/v1/password-reset',
        'GET api/v1/sso/config', 'GET api/v1/sso/start', 'GET api/v1/sso/callback', 'POST api/v1/sso/saml/acs', 'GET api/v1/sso/saml/metadata', // SEC-12: SSO browser flow; state/nonce/PKCE bound, throttled
        'POST api/v1/login/mfa', // SEC-12: second login step; the single-use, 5-minute challenge is the credential; throttled
        'POST api/v1/support/session', // PLAT-8: one-time-code exchange, throttled, tenant-bound, single use
        // FUN-11: a client paying an invoice; the 40-character payment-link secret is the credential, throttled
        'GET api/v1/pay/{token}', 'POST api/v1/pay/{token}/mpesa', 'POST api/v1/pay/{token}/card', 'GET api/v1/pay/{token}/attempts/{attempt}',
        // FUN-11: provider callbacks, authenticated by the per-attempt URL secret (M-Pesa) or the webhook signature (Stripe)
        'POST api/v1/pay/callback/mpesa/{secret}', 'POST api/v1/pay/callback/stripe',
        // FUN-14: a signer's personal link (48-character secret) is the credential; throttled
        'GET api/v1/sign/{token}', 'GET api/v1/sign/{token}/document', 'POST api/v1/sign/{token}', 'POST api/v1/sign/{token}/decline',
        // FUN-15: inbound mail webhook; a 32+ character deployment secret in the URL (and Mailgun's signature when set)
        'POST api/v1/inbound/email/{secret}',
        'POST api/v1/csp-report', // SEC-14: browsers' CSP violation reports; no credentials, throttled, logs whitelisted fields only
        // FUN-10: client portal sign-in (a code texted to the client's phone; throttled, no enumeration)
        'POST api/v1/portal/login', 'POST api/v1/portal/verify',
    ];

    /** @return array<int, array{key:string, method:string, uri:string, mw:array}> */
    private function v1Routes(): array
    {
        $out = [];
        foreach (Route::getRoutes() as $r) {
            if (! str_starts_with($r->uri(), 'api/v1/')) {
                continue;
            }
            $method = collect($r->methods())->first(fn ($m) => $m !== 'HEAD');
            $out[] = ['key' => "{$method} {$r->uri()}", 'method' => $method, 'uri' => $r->uri(), 'mw' => $r->gatherMiddleware()];
        }

        return $out;
    }

    private function declaration(array $mw): ?string
    {
        return collect($mw)->first(fn ($m) => is_string($m) && str_starts_with($m, 'authz:'));
    }

    public function test_every_route_is_public_by_allowlist_or_authenticated_and_declared(): void
    {
        foreach ($this->v1Routes() as $r) {
            if (in_array($r['key'], self::PUBLIC_ROUTES, true)) {
                continue;
            }

            // PLAT-11: the public API is authenticated by a scoped API key instead of a session
            if (collect($r['mw'])->contains(fn ($m) => is_string($m) && str_starts_with($m, 'api.key:'))) {
                $this->assertStringStartsWith('api/v1/public/', $r['uri'], "{$r['key']} uses an API key outside the public prefix.");
                $this->assertSame('GET', $r['method'], "{$r['key']}: the public API is read-only.");
                $this->assertNotContains('auth:sanctum', $r['mw']);

                continue;
            }
            // SEC-12: SCIM provisioning is authenticated by a firm-issued bearer token, only under its own prefix
            if (collect($r['mw'])->contains(fn ($m) => is_string($m) && str_starts_with($m, 'scim.token'))) {
                $this->assertStringStartsWith('api/v1/scim/v2/', $r['uri'], "{$r['key']} uses a SCIM token outside the SCIM prefix.");
                $this->assertNotContains('auth:sanctum', $r['mw']);

                continue;
            }
            // FUN-10: the client portal is authenticated by a portal session (a client, never a staff login)
            if (in_array('portal.session', $r['mw'], true)) {
                $this->assertStringStartsWith('api/v1/portal/', $r['uri'], "{$r['key']} uses a portal session outside the portal prefix.");
                $this->assertNotContains('auth:sanctum', $r['mw']);

                continue;
            }
            $this->assertContains('auth:sanctum', $r['mw'], "{$r['key']} is reachable without authentication and is not in the public allowlist.");
            $this->assertContains('authz.guard', $r['mw'], "{$r['key']} is not behind the deny-by-default guard.");
            $this->assertNotNull($this->declaration($r['mw']), "{$r['key']} has no authz declaration.");
        }
    }

    public function test_no_route_leaves_authorization_to_its_controller(): void
    {
        $inline = collect($this->v1Routes())->filter(fn ($r) => $this->declaration($r['mw']) === 'authz:inline')->pluck('key')->all();

        $this->assertSame([], $inline, 'authz:inline no longer exists: declare authz:permission, authz:policy or authz:authenticated.');
    }

    public function test_route_without_declaration_is_denied_before_the_controller(): void
    {
        $reached = false;
        $route = new LaravelRoute('GET', '/whatever', fn () => 'ok');
        $request = Request::create('/whatever');
        $request->setRouteResolver(fn () => $route);

        try {
            (new RequireAuthzDeclaration)->handle($request, function () use (&$reached) {
                $reached = true;
            });
            $this->fail('undeclared route must be denied');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertFalse($reached);
    }

    public function test_declared_route_passes_the_guard(): void
    {
        $route = (new LaravelRoute('GET', '/whatever', fn () => 'ok'))->middleware('authz:authenticated');
        $request = Request::create('/whatever');
        $request->setRouteResolver(fn () => $route);

        $response = (new RequireAuthzDeclaration)->handle($request, fn () => response('through'));

        $this->assertSame('through', $response->getContent());
    }

    public function test_unknown_authz_mode_fails_closed(): void
    {
        $route = (new LaravelRoute('GET', '/x', fn () => 'ok'))->middleware('authz:typo');
        $request = Request::create('/x');
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn () => $this->userWithPermissions([]));

        $this->expectException(\LogicException::class);
        (new AuthorizeRoute)->handle($request, fn () => response('should not run'), 'typo');
    }

    /** @return array<string, array{0:string,1:string,2:string,3:string}> */
    public static function enforcedRoutes(): array
    {
        return [
            'court types list' => ['get', '/api/v1/courttypes', 'list-court', 'list-expenses'],
            'courts create' => ['post', '/api/v1/courts', 'create-court', 'list-court'],
            'courts update' => ['put', '/api/v1/courts/1', 'update-court', 'create-court'],
            'courts delete' => ['delete', '/api/v1/courts/1', 'delete-court', 'update-court'],
            'hearing types list' => ['get', '/api/v1/hearingtypes', 'list-hearings', 'list-court'],
            'expense categories list' => ['get', '/api/v1/expense-categories', 'list-expenses', 'list-hearings'],
        ];
    }

    /** @dataProvider enforcedRoutes */
    public function test_route_level_permission_is_enforced_before_the_controller(string $method, string $url, string $needed, string $other): void
    {
        $this->assertSame(401, $this->json($method, $url)->status(), 'unauthenticated');

        $none = $this->userWithPermissions([]);
        $this->assertSame(403, $this->actingAs($none)->json($method, $url)->status(), 'no permissions');

        $wrong = $this->userWithPermissions([$other]);
        $this->assertSame(403, $this->actingAs($wrong)->json($method, $url)->status(), 'another module\'s permission');

        $right = $this->userWithPermissions([$needed]);
        $this->assertNotSame(403, $this->actingAs($right)->json($method, $url)->status(), 'the right permission passes the route gate');
    }

    public function test_removed_scaffold_routes_no_longer_exist(): void
    {
        $user = $this->adminUser();

        foreach (['/api/v1/courts/create', '/api/v1/users/1', '/api/v1/documents', '/api/v1/invoiceItems'] as $url) {
            $status = $this->actingAs($user)->getJson($url)->status();
            $this->assertContains($status, [404, 405], "{$url} should not be routed (got {$status})");
        }
    }

    public function test_policy_routes_never_succeed_for_a_user_with_no_permissions(): void
    {
        $user = $this->userWithPermissions([]);
        $checked = 0;

        foreach ($this->v1Routes() as $r) {
            if (! str_starts_with((string) $this->declaration($r['mw']), 'authz:policy')) {
                continue;
            }
            $checked++;
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $r['uri']);
            $status = $this->actingAs($user)->json($r['method'], $uri, ['ids' => [1]])->status();

            $this->assertTrue(in_array($status, [403, 404], true), "{$r['key']} answered {$status} to a user with no permissions.");
        }
        $this->assertGreaterThan(0, $checked);
    }

    public function test_route_cache_builds_with_the_legacy_alias_present(): void
    {
        $this->assertSame(0, Artisan::call('route:cache'));
        Artisan::call('route:clear');
    }
}
