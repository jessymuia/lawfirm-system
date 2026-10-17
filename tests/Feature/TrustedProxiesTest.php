<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** PLAT-5: behind a TLS proxy the app must see https, but only when told to trust that proxy. */
class TrustedProxiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('/api/v1/__scheme', fn (\Illuminate\Http\Request $r) => response()->json(['secure' => $r->isSecure(), 'host' => $r->getHost()]));
    }

    private function viaProxy()
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->get('/api/v1/__scheme', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'firm.example.com']);
    }

    public function test_forwarded_headers_are_ignored_by_default(): void
    {
        config(['app.trusted_proxies' => '']);

        $this->viaProxy()->assertJson(['secure' => false]);
    }

    public function test_a_listed_proxy_is_trusted(): void
    {
        config(['app.trusted_proxies' => '10.0.0.0/8, 192.168.0.0/16']);

        $this->viaProxy()->assertJson(['secure' => true, 'host' => 'firm.example.com']);
    }

    public function test_an_unlisted_source_is_not_trusted(): void
    {
        config(['app.trusted_proxies' => '172.16.0.0/12']);

        $this->viaProxy()->assertJson(['secure' => false]);
    }

    public function test_star_trusts_any_proxy(): void
    {
        config(['app.trusted_proxies' => '*']);

        $this->viaProxy()->assertJson(['secure' => true]);
    }
}
