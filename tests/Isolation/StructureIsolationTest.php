<?php

namespace Tests\Isolation;

use App\Entitlements\Entitlements;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** TEN-10: the preconditions of everything else in this suite, and the credentials that are not sessions (API keys). */
class StructureIsolationTest extends IsolationTestCase
{
    public function test_the_suite_really_runs_against_two_provisioned_active_firms_with_separate_databases(): void
    {
        $this->assertCount(2, $this->firm);
        $this->assertSame(['active', 'active'], Tenant::whereIn('slug', ['a', 'b'])->orderBy('slug')->pluck('status')->all());
        $dbs = array_map(fn ($f) => $f['tenant']->database, $this->firm);
        $this->assertCount(2, array_unique($dbs), 'each firm needs its own database');
        $names = array_map(fn ($s) => $this->inFirm($s, fn () => DB::getDatabaseName()), ['a', 'b']);
        $this->assertCount(2, array_unique($names));
        $this->assertSame(2, $this->inFirm('a', fn () => DB::table('clients')->count()));
        $this->assertSame(5, $this->inFirm('b', fn () => DB::table('clients')->count()), 'the firms must hold different data, or an isolation check proves nothing');
        $this->assertSame('multi', config('tenancy.mode'));
    }

    public function test_control_plane_tables_are_not_in_a_firms_database_and_the_reverse(): void
    {
        $control = array_map(fn ($t) => $t['name'], Schema::connection('control')->getTables());
        foreach (['a', 'b'] as $slug) {
            $firmTables = $this->inFirm($slug, fn () => array_map(fn ($t) => $t['name'], Schema::getTables()));
            $this->assertSame([], array_values(array_intersect(['tenants', 'tenant_domains', 'identities', 'platform_staff'], $firmTables)), "{$slug}'s database holds control-plane tables");
            $this->assertNotContains('clients', $control, 'the control plane must not hold firm data');
            $this->assertNotContains('cases', $control);
        }
        $this->assertContains('tenants', $control);
    }

    public function test_a_host_that_belongs_to_no_firm_gets_nothing_and_never_a_default_firm(): void
    {
        foreach (['http://ghost.app.test', 'http://app.test', 'http://a.app.test.evil.test'] as $host) {
            $r = $this->http($host, 'GET', '/clients', [], $this->firm['a']['cookies']);
            $this->assertSame(404, $r->getStatusCode(), "{$host} must not resolve to any firm");
            $this->assertStringNotContainsString('CANARY', $r->getContent());
        }
        app(TenantManager::class)->failClosed();
        $this->expectException(\InvalidArgumentException::class);
        DB::table('clients')->count();
    }

    public function test_an_api_key_belongs_to_one_firm(): void
    {
        config(['sso.block_private_hosts' => false]);
        Tenant::query()->update(['plan' => 'professional']);
        app(Entitlements::class)->forget();
        $a = $this->api('a', 'POST', '/integrations/api-keys', ['name' => 'k', 'scopes' => ['clients:read']])->assertCreated()->json('data.key');
        $b = $this->api('b', 'POST', '/integrations/api-keys', ['name' => 'k', 'scopes' => ['clients:read']])->assertCreated()->json('data.key');

        $own = $this->http($this->firm['a']['host'], 'GET', '/public/clients', [], [], ['Authorization' => "Bearer {$a}"])->assertOk();
        $this->assertCount(2, $own->json('data'));
        $this->assertStringNotContainsString('CANARY-B', $own->getContent());
        $theirs = $this->http($this->firm['b']['host'], 'GET', '/public/clients', [], [], ['Authorization' => "Bearer {$b}"])->assertOk();
        $this->assertCount(5, $theirs->json('data'));
        $this->assertStringNotContainsString('CANARY-A', $theirs->getContent());

        // A's key presented to B's host (and the reverse) is just an unknown key there
        $this->http($this->firm['b']['host'], 'GET', '/public/clients', [], [], ['Authorization' => "Bearer {$a}"])->assertStatus(401);
        $this->http($this->firm['a']['host'], 'GET', '/public/clients', [], [], ['Authorization' => "Bearer {$b}"])->assertStatus(401);
    }

    public function test_a_session_is_not_an_api_key_and_a_key_is_not_a_session(): void
    {
        config(['sso.block_private_hosts' => false]);
        Tenant::query()->update(['plan' => 'professional']);
        app(Entitlements::class)->forget();
        $a = $this->api('a', 'POST', '/integrations/api-keys', ['name' => 'k', 'scopes' => ['clients:read']])->assertCreated()->json('data.key');

        $this->http($this->firm['a']['host'], 'GET', '/clients', [], [], ['Authorization' => "Bearer {$a}"])->assertStatus(401);
        $this->http($this->firm['a']['host'], 'GET', '/public/clients', [], $this->firm['a']['cookies'])->assertStatus(401);
    }
}
