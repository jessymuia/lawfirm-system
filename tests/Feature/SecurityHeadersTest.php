<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** SEC-14: browser hardening headers and the CSP violation report endpoint. */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_hardening_headers_and_hsts_only_over_https(): void
    {
        $plain = $this->get('/health/live');
        $plain->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('Strict-Transport-Security');
        $this->assertStringContainsString('camera=()', (string) $plain->headers->get('Permissions-Policy'));

        $tls = $this->get('https://localhost/health/live');
        $tls->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $csp = (string) $tls->headers->get('Content-Security-Policy-Report-Only');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString('report-uri /api/v1/csp-report', $csp);
        $tls->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_the_policy_can_be_enforced_or_turned_off_and_hsts_disabled(): void
    {
        config(['security_headers.csp_mode' => 'enforce', 'security_headers.hsts_max_age' => 0]);
        $r = $this->get('https://localhost/health/live');
        $this->assertNotEmpty($r->headers->get('Content-Security-Policy'));
        $r->assertHeaderMissing('Content-Security-Policy-Report-Only')->assertHeaderMissing('Strict-Transport-Security');

        // a page that sets its own policy (the status page, the nonce-based standalone pages) keeps it, with no second one
        Route::post('/own-policy', fn () => response('ok', 200, ['Content-Security-Policy' => "default-src 'none'"]));
        foreach (['enforce', 'report-only'] as $mode) {
            config(['security_headers.csp_mode' => $mode]);
            $this->post('/own-policy')->assertHeader('Content-Security-Policy', "default-src 'none'")
                ->assertHeaderMissing('Content-Security-Policy-Report-Only');
        }

        config(['security_headers.csp_mode' => 'off']);
        $this->get('/health/live')->assertHeaderMissing('Content-Security-Policy')->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_violation_reports_are_logged_without_page_content_and_need_no_csrf_token(): void
    {
        Log::spy();
        $body = json_encode(['csp-report' => ['document-uri' => 'https://firm.app.test/cases/7', 'violated-directive' => 'script-src',
            'blocked-uri' => 'https://evil.example/x.js', 'original-policy' => '…', 'script-sample' => 'secret client text']]);
        $this->call('POST', '/api/v1/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report', 'HTTP_ORIGIN' => 'http://localhost', 'HTTP_REFERER' => 'http://localhost/'], $body)
            ->assertNoContent();
        Log::shouldHaveReceived('warning')->once()->withArgs(function ($msg, $ctx) {
            $this->assertSame('Content-Security-Policy violation', $msg);
            $this->assertSame('https://evil.example/x.js', $ctx['blocked-uri']);
            $this->assertArrayNotHasKey('script-sample', $ctx, 'page content is not kept');

            return true;
        });

        $this->call('POST', '/api/v1/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')->assertNoContent();
        Log::shouldHaveReceived('warning')->once();
    }
}
