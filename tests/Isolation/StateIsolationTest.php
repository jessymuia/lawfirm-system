<?php

namespace Tests\Isolation;

use App\Entitlements\Entitlements;
use App\Models\User;
use App\Tenancy\IdentityService;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * TEN-6 / TEN-10: in-process and shared state: cache entries, locks, rate-limit counters, the permission registry, plan and flag
 * memoisation. All of it is exercised by switching firms inside ONE process, which is what a queue worker or Octane does.
 */
class StateIsolationTest extends IsolationTestCase
{
    public function test_cache_entries_are_per_firm(): void
    {
        $this->persistentCache();
        $this->inFirm('a', fn () => Cache::put('shared-key', 'value-of-A', 600));
        $this->inFirm('a', fn () => Cache::forever('forever-key', 'forever-A'));

        $this->assertNull($this->inFirm('b', fn () => Cache::get('shared-key')), 'B must not read A\'s entry');
        $this->assertNull($this->inFirm('b', fn () => Cache::get('forever-key')));
        $this->assertFalse($this->inFirm('b', fn () => Cache::has('shared-key')));

        $this->inFirm('b', fn () => Cache::put('shared-key', 'value-of-B', 600));
        $this->assertSame('value-of-A', $this->inFirm('a', fn () => Cache::get('shared-key')), 'B writing the same key must not overwrite A');
        $this->assertSame('value-of-B', $this->inFirm('b', fn () => Cache::get('shared-key')));

        $this->inFirm('b', fn () => Cache::flush());
        $this->assertSame('value-of-A', $this->inFirm('a', fn () => Cache::get('shared-key')), 'flushing B must not flush A');
    }

    /** A store that is physically shared (as Redis or a central database is) can only be separated by the key prefix. */
    public function test_a_physically_shared_cache_store_is_separated_by_prefix(): void
    {
        Schema::connection('control')->create('shared_cache', function ($t) {
            $t->string('key')->primary();
            $t->mediumText('value');
            $t->integer('expiration');
        });
        config(['cache.stores.shared' => ['driver' => 'database', 'connection' => 'control', 'table' => 'shared_cache', 'lock_connection' => 'control', 'lock_table' => 'shared_cache_locks'], 'cache.default' => 'shared']);
        app('cache')->forgetDriver();
        app()->forgetInstance('cache.store');

        $this->inFirm('a', fn () => Cache::put('k', 'value-of-A', 600));
        $this->assertNull($this->inFirm('b', fn () => Cache::get('k')), 'B read A\'s entry from a shared store');
        $this->inFirm('b', fn () => Cache::put('k', 'value-of-B', 600));
        $this->assertSame('value-of-A', $this->inFirm('a', fn () => Cache::get('k')));
        $this->assertSame(2, DB::connection('control')->table('shared_cache')->count(), 'two firms, two distinct rows in the one shared table');
    }

    public function test_cache_locks_and_counters_are_per_firm(): void
    {
        $this->persistentCache();
        $lockA = $this->inFirm('a', fn () => Cache::lock('nightly-job', 30)->get());
        $this->assertTrue($lockA);
        $this->assertTrue($this->inFirm('b', fn () => Cache::lock('nightly-job', 30)->get()), 'A holding a lock must not block B');

        $this->inFirm('a', fn () => Cache::increment('counter', 5));
        $this->assertSame(1, $this->inFirm('b', fn () => Cache::increment('counter')));
        $this->inFirm('a', fn () => RateLimiter::hit('k', 60));
        $this->assertSame(0, $this->inFirm('b', fn () => RateLimiter::attempts('k')));
    }

    public function test_failed_logins_in_one_firm_do_not_lock_the_same_address_in_another(): void
    {
        $this->persistentCache();
        // In multi mode a login is a control-plane identity with a membership per firm: one person who belongs to both firms.
        [$identity] = app(IdentityService::class)->registerOrReuse('shared@x.test', 'Shared-pass-1');
        foreach (['a', 'b'] as $slug) {
            $this->inFirm($slug, function () use ($identity, $slug) {
                $u = User::factory()->create(['email' => 'shared@x.test']);
                app(IdentityService::class)->link($identity, $this->firm[$slug]['tenant'], $u);
            });
        }
        for ($i = 0; $i < 5; $i++) {
            $this->http($this->firm['a']['host'], 'POST', '/login', ['email' => 'shared@x.test', 'password' => 'wrong'])->assertStatus(401);
        }
        $this->http($this->firm['a']['host'], 'POST', '/login', ['email' => 'shared@x.test', 'password' => 'Shared-pass-1'])->assertStatus(429);   // A is locked out
        $this->http($this->firm['b']['host'], 'POST', '/login', ['email' => 'shared@x.test', 'password' => 'Shared-pass-1'])->assertOk();           // B is not
    }

    public function test_the_permission_registry_is_per_firm(): void
    {
        $this->assertNotSame(
            $this->inFirm('a', fn () => config('permission.cache.key')),
            $this->inFirm('b', fn () => config('permission.cache.key')),
        );
        // a permission that exists only in A, warmed into the registry while A is active ...
        $this->inFirm('a', function () {
            Permission::create(['name' => 'canary-a-only', 'guard_name' => 'web']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->assertTrue(app(PermissionRegistrar::class)->getPermissions()->contains('name', 'canary-a-only'));
        });
        // ... must not be there when B becomes active in the same process
        $this->inFirm('b', function () {
            $this->assertFalse(app(PermissionRegistrar::class)->getPermissions()->contains('name', 'canary-a-only'), 'the permission registry leaked from firm A into firm B');
            $this->assertSame(0, Permission::where('name', 'canary-a-only')->count());
        });
    }

    public function test_a_users_permissions_are_decided_by_their_own_firms_roles(): void
    {
        // same permission NAME, different ids in the two firms: a registry that leaked would map B's check onto A's id
        $this->inFirm('a', function () {
            Permission::create(['name' => 'pad-1', 'guard_name' => 'web']);
            Permission::create(['name' => 'pad-2', 'guard_name' => 'web']);
            Permission::create(['name' => 'shared-late', 'guard_name' => 'web']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
        $this->inFirm('b', function () {
            Permission::create(['name' => 'shared-late', 'guard_name' => 'web']);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        });
        $idA = $this->inFirm('a', fn () => Permission::findByName('shared-late', 'web')->id);
        $idB = $this->inFirm('b', fn () => Permission::findByName('shared-late', 'web')->id);
        $this->assertNotSame($idA, $idB);

        $this->inFirm('a', fn () => User::where('email', 'admin@a.test')->first()->givePermissionTo('shared-late'));
        $this->assertTrue($this->inFirm('a', fn () => User::where('email', 'admin@a.test')->first()->checkPermissionTo('shared-late')));
        $this->assertFalse($this->inFirm('b', fn () => User::where('email', 'admin@b.test')->first()->checkPermissionTo('shared-late')), 'B\'s admin must not inherit A\'s grant');
    }

    public function test_plan_and_entitlements_follow_the_firm_not_the_process(): void
    {
        Tenant::where('slug', 'a')->update(['plan' => 'trial']);
        Tenant::where('slug', 'b')->update(['plan' => 'professional']);
        app(Entitlements::class)->forget();   // the plan was changed behind the process's back (as the platform console would, from another process)
        foreach (range(1, 3) as $_) {
            $a = $this->api('a', 'GET', '/entitlements')->assertOk();
            $b = $this->api('b', 'GET', '/entitlements')->assertOk();
            $this->assertStringNotContainsString('api_access":true', str_replace(' ', '', $a->getContent()));
            $this->assertStringContainsString('api_access":true', str_replace(' ', '', $b->getContent()));
        }
        // and the gate itself, not just the report
        $this->api('a', 'GET', '/integrations/api-keys')->assertStatus(403);
        $this->api('b', 'GET', '/integrations/api-keys')->assertOk();
        $this->api('a', 'GET', '/integrations/api-keys')->assertStatus(403);
    }

    public function test_feature_flag_overrides_are_per_firm(): void
    {
        Tenant::where('slug', 'a')->update(['flags' => ['hearing_sms_reminders' => false]]);
        foreach (range(1, 2) as $_) {
            $a = $this->api('a', 'GET', '/flags')->assertOk();
            $b = $this->api('b', 'GET', '/flags')->assertOk();
            $this->assertNotSame($a->getContent(), $b->getContent(), 'A\'s override must not apply to B (or hide from A)');
        }
        $this->assertStringContainsString('hearing_sms_reminders', $this->api('a', 'GET', '/flags')->getContent());
    }
}
