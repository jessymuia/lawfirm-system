<?php

namespace Tests\Isolation;

use Illuminate\Support\Facades\Route;

/**
 * TEN-10: what firm A's authenticated session can read or change over the API. The first test sweeps EVERY parameterless and
 * single-parameter GET route registered under /api/v1 (so a new endpoint is covered the day it is added) and looks for the other
 * firm's canary in everything that comes back.
 */
class ApiIsolationTest extends IsolationTestCase
{
    /** Routes that are not firm data or need a different credential; each is covered elsewhere or has nothing to leak. */
    private const SWEEP_SKIP = ['api/v1/sso/callback', 'api/v1/sso/start', 'api/v1/export/firm-data'];

    /** @return array{0:string[],1:string[]} parameterless and single-parameter GET uris */
    private function getRoutes(): array
    {
        $none = $one = [];
        foreach (Route::getRoutes() as $r) {
            $u = $r->uri();
            if (! str_starts_with($u, 'api/v1/') || ! in_array('GET', $r->methods(), true) || in_array($u, self::SWEEP_SKIP, true)) {
                continue;
            }
            match (substr_count($u, '{')) {
                0 => $none[] = substr($u, 6),
                1 => $one[] = substr($u, 6),
                default => null,
            };
        }
        sort($none);
        sort($one);

        return [$none, $one];
    }

    public function test_the_sweep_really_covers_the_api(): void
    {
        [$none, $one] = $this->getRoutes();
        $this->assertGreaterThan(40, count($none));
        $this->assertGreaterThan(15, count($one));
        foreach (['/clients', '/cases', '/hearings', '/tasks', '/users', '/search', '/dashboard', '/invoices', '/expenses'] as $must) {
            $this->assertContains($must, $none);
        }
    }

    /** @dataProvider direction */
    public function test_no_get_route_returns_the_other_firms_data(string $me, string $other): void
    {
        [$none, $one] = $this->getRoutes();
        $checked = 0;
        foreach ($none as $uri) {
            $q = $uri === '/search' ? '?q=CANARY' : '';
            $this->assertNoLeak($this->api($me, 'GET', $uri.$q), $other, "GET {$uri}");
            $checked++;
        }
        foreach ($one as $uri) {
            foreach (range(1, 7) as $id) {        // ids that exist only in the bigger firm are in this range
                $this->assertNoLeak($this->api($me, 'GET', preg_replace('/\{[^}]+\}/', (string) $id, $uri)), $other, "GET {$uri} id {$id}");
                $checked++;
            }
        }
        $this->assertGreaterThan(150, $checked);
    }

    public static function direction(): array
    {
        return ['A cannot see B' => ['a', 'b'], 'B cannot see A' => ['b', 'a']];
    }

    public function test_lists_return_only_the_callers_rows(): void
    {
        $this->assertSame(2, count($this->api('a', 'GET', '/clients?per_page=100')->assertOk()->json('data.data')));
        $this->assertSame(5, count($this->api('b', 'GET', '/clients?per_page=100')->assertOk()->json('data.data')));
        $this->assertStringContainsString('CANARY-A', $this->api('a', 'GET', '/cases?per_page=100')->getContent());
        $this->assertStringContainsString('CANARY-B', $this->api('b', 'GET', '/cases?per_page=100')->getContent());
        foreach (['per_page=100000', 'per_page=-1', 'page=2&per_page=1', 'all=1', 'search=CANARY-B', 'sort=id&order=desc'] as $q) {
            $this->assertNoLeak($this->api('a', 'GET', "/clients?{$q}"), 'b', "clients?{$q}");
            $this->assertNoLeak($this->api('a', 'GET', "/cases?{$q}"), 'b', "cases?{$q}");
        }
    }

    public function test_ids_that_exist_only_in_the_other_firm_are_not_found(): void
    {
        $only = $this->idsOnlyIn('b', 'clients', 'a');
        $this->assertNotEmpty($only);
        foreach ($only as $id) {
            $this->api('a', 'GET', "/clients/{$id}")->assertNotFound();
            $this->api('a', 'PUT', "/clients/{$id}", ['name' => 'HACKED', 'phone_number' => '0700000999', 'address' => 'x', 'advocate' => 1])->assertNotFound();
            $this->api('a', 'DELETE', "/clients/{$id}")->assertNotFound();
            $this->api('a', 'POST', "/clients/{$id}/restore")->assertNotFound();
        }
        $onlyCases = $this->idsOnlyIn('b', 'cases', 'a');
        $this->assertNotEmpty($onlyCases);
        foreach ($onlyCases as $id) {
            $this->api('a', 'GET', "/cases/{$id}")->assertNotFound();
            $this->api('a', 'DELETE', "/cases/{$id}")->assertNotFound();
        }
        $this->assertNothingChangedIn('b');
    }

    public function test_the_same_id_means_the_callers_own_row(): void
    {
        $a = $this->api('a', 'GET', '/clients/1')->assertOk();
        $b = $this->api('b', 'GET', '/clients/1')->assertOk();
        $this->assertStringContainsString('CANARY-A', $a->getContent());
        $this->assertStringContainsString('CANARY-B', $b->getContent());
        $this->assertNoLeak($a, 'b');
        $this->assertNoLeak($b, 'a');
    }

    public function test_bulk_and_ownership_operations_cannot_reach_the_other_firm(): void
    {
        $only = $this->idsOnlyIn('b', 'clients', 'a');
        $this->api('a', 'POST', '/clients/bulk-delete', ['ids' => $only]);
        $this->api('a', 'POST', '/cases/bulk-delete', ['ids' => $this->idsOnlyIn('b', 'cases', 'a')]);
        // a create in A that references a B-only id must be refused, not stored
        $this->api('a', 'POST', '/cases', ['case_number' => 'X-1', 'client_id' => $only[0], 'description' => 'x'])->assertStatus(422);
        $this->assertNothingChangedIn('b');
    }

    public function test_search_is_scoped_to_the_callers_firm(): void
    {
        $r = $this->api('a', 'GET', '/search?q=CANARY')->assertOk();
        $this->assertStringContainsString('CANARY-A', $r->getContent());
        $this->assertNoLeak($r, 'b');
        $none = $this->api('a', 'GET', '/search?q=CANARY-B')->assertOk();
        $this->assertSame([], array_filter($none->json('cases') ?? []));
        $this->assertSame([], array_filter($none->json('clients') ?? []));
        $this->assertNoLeak($none, 'b');
    }

    public function test_a_forged_host_does_not_change_whose_data_is_served(): void
    {
        foreach (['X-Forwarded-Host' => 'b.app.test', 'X-Original-Host' => 'b.app.test', 'X-Host' => 'b.app.test', 'Forwarded' => 'host=b.app.test', 'X-Tenant' => 'b', 'X-Tenant-Id' => 'b'] as $h => $v) {
            $r = $this->api('a', 'GET', '/user', [], null, [$h => $v]);
            $r->assertOk();
            $this->assertSame('admin@a.test', $r->json('email'), "header {$h} must not select the firm");
        }
        // an unknown or look-alike host serves nothing, and never falls back to a firm
        foreach (['http://ghost.app.test', 'http://a.app.test.evil.test', 'http://xa.app.test', 'http://app.test'] as $host) {
            $this->http($host, 'GET', '/clients', [], $this->firm['a']['cookies'])->assertNotFound();
        }
    }

    public function test_the_control_plane_is_not_reachable_from_a_firm_host_and_the_firm_api_not_from_the_platform_host(): void
    {
        $this->http($this->firm['a']['host'], 'GET', '/../platform/tenants', [], $this->firm['a']['cookies']);
        $r = $this->call('GET', $this->firm['a']['host'].'/api/platform/tenants', [], $this->firm['a']['cookies']);
        $this->assertContains($r->status(), [404, 401, 403]);
        $this->assertStringNotContainsString('firm-b', $r->getContent());
        $this->assertStringNotContainsString('b.app.test', $r->getContent());
        $this->http('http://admin.platform.test', 'GET', '/clients', [], $this->firm['a']['cookies'])->assertNotFound();
    }

    private function assertNothingChangedIn(string $slug): void
    {
        $this->inFirm($slug, function () {
            $this->assertSame(5, \App\Models\Client::count());
            $this->assertSame(5, \App\Models\Cases::count());
            $this->assertSame(0, \App\Models\Client::where('name', 'HACKED')->count());
            $this->assertSame(0, \App\Models\Client::onlyTrashed()->count());
            $this->assertSame(0, \App\Models\Cases::onlyTrashed()->count());
        });
    }
}
