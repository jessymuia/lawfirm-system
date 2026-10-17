<?php

namespace Tests\Isolation;

use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * TEN-10: a login is for one firm only. Found by this suite: a session started on firm A and replayed against firm B's host
 * authenticated as B's user with the same id (B's administrator), because sessions lived in one shared store under one key.
 */
class SessionIsolationTest extends IsolationTestCase
{
    private function email(string $slug, ?string $cookiesOf = null)
    {
        return $this->api($slug, 'GET', '/user', cookiesOf: $cookiesOf);
    }

    public function test_each_firms_own_session_works_on_its_own_host(): void
    {
        $this->email('a')->assertOk()->assertJsonPath('email', 'admin@a.test');
        $this->email('b')->assertOk()->assertJsonPath('email', 'admin@b.test');
    }

    public function test_a_session_from_one_firm_does_not_authenticate_on_another_firms_host(): void
    {
        $this->email('b', cookiesOf: 'a')->assertUnauthorized();
        $this->email('a', cookiesOf: 'b')->assertUnauthorized();
        $this->api('b', 'GET', '/clients', cookiesOf: 'a')->assertUnauthorized();
    }

    public function test_the_session_cookie_is_not_accepted_under_another_name(): void
    {
        $a = $this->firm['a']['cookies'];
        $sessionA = $a['laravel_session_a'];
        $this->assertNotSame('laravel_session', array_keys($a)[1] ?? '', 'cookie names are per firm');
        foreach (['laravel_session', 'laravel_session_b'] as $name) {
            $this->http($this->firm['b']['host'], 'GET', '/user', [], [$name => $sessionA])->assertUnauthorized();
        }
    }

    /**
     * Each firm's stored sessions, raw: file path => contents (file driver), or Redis key => value (redis driver, one
     * shared server, where only the firm's cache prefix separates the keys).
     *
     * @return array<string, string>
     */
    private function storedSessions(string $slug): array
    {
        if (! static::driver('redis')) {
            return collect(glob($this->dir."/sessions/{$slug}/*") ?: [])->mapWithKeys(fn ($f) => [$f => file_get_contents($f)])->all();
        }
        $redis = app('redis')->connection('default');
        $global = (string) config('database.redis.options.prefix');
        $firm = $this->inFirm($slug, fn () => (string) config('cache.prefix'));
        $out = [];
        foreach ($redis->keys('*') as $key) {
            $key = str_starts_with($key, $global) ? substr($key, strlen($global)) : $key;
            if (str_starts_with($key, $firm)) {
                $out[$key] = (string) $redis->get($key);
            }
        }

        return $out;
    }

    private function overwriteStoredSession(string $where, string $payload): void
    {
        static::driver('redis') ? app('redis')->connection('default')->set($where, $payload) : file_put_contents($where, $payload);
    }

    public function test_a_shared_session_store_cannot_log_one_firm_into_another(): void
    {
        // Worst case: both firms' sessions end up in the same store (a shared Redis, a copied file) and A's payload is
        // presented under B's own, valid session id. The login key inside the payload is per firm, so it means nothing to B.
        $a = $this->storedSessions('a');
        $b = $this->storedSessions('b');
        $this->assertCount(1, $a);
        $this->assertCount(1, $b);
        $payloadA = reset($a);
        $this->assertStringContainsString('login_web_', $payloadA);
        $this->overwriteStoredSession(array_key_first($b), $payloadA);   // B's session id, A's content

        $this->email('b')->assertUnauthorized();
    }

    public function test_sessions_are_stored_apart_per_firm(): void
    {
        $a = $this->storedSessions('a');
        $b = $this->storedSessions('b');
        $this->assertCount(1, $a);
        $this->assertCount(1, $b);
        $this->assertSame([], array_intersect_key($a, $b), 'no stored session belongs to both firms');
        if (static::driver('redis')) {
            $this->assertNotSame($this->inFirm('a', fn () => config('cache.prefix')), $this->inFirm('b', fn () => config('cache.prefix')));
        } else {
            $this->assertSame([], glob($this->dir.'/sessions/*') === false ? [] : array_filter(glob($this->dir.'/sessions/*'), 'is_file'), 'nothing in the shared root');
            $this->assertNotSame(basename(array_key_first($a)), basename(array_key_first($b)));
        }
    }

    public function test_interleaved_requests_never_see_the_other_firms_user(): void
    {
        foreach (range(1, 3) as $_) {
            $this->email('a')->assertOk()->assertJsonPath('email', 'admin@a.test');
            $this->email('b')->assertOk()->assertJsonPath('email', 'admin@b.test');
        }
    }

    public function test_logging_out_of_one_firm_leaves_the_other_logged_in(): void
    {
        $this->api('a', 'POST', '/logout')->assertOk();
        $this->email('a')->assertUnauthorized();
        $this->email('b')->assertOk()->assertJsonPath('email', 'admin@b.test');
    }

    public function test_the_login_key_and_remember_cookie_are_per_firm_but_unchanged_for_a_single_firm_install(): void
    {
        $name = fn () => [Auth::guard('web')->getName(), Auth::guard('web')->getRecallerName()];
        $a = $this->inFirm('a', $name);
        $b = $this->inFirm('b', $name);
        $this->assertNotSame($a[0], $b[0]);
        $this->assertNotSame($a[1], $b[1]);

        // a single-firm install runs under the default key: the names are exactly Laravel's, so existing sessions survive the upgrade
        app(\App\Tenancy\TenantContext::class)->activate('default');
        $this->assertSame(['login_web_'.sha1(SessionGuard::class), 'remember_web_'.sha1(SessionGuard::class)], $name());
    }

    public function test_the_session_cookie_name_follows_the_firm_and_returns_to_laravels_own_for_a_single_firm_install(): void
    {
        app(\App\Tenancy\TenantContext::class)->activate('default');
        $base = config('session.cookie');
        $this->assertStringEndsWith('_session', $base);
        $a = $this->inFirm('a', fn () => config('session.cookie'));
        $b = $this->inFirm('b', fn () => config('session.cookie'));
        $this->assertSame([$base.'_a', $base.'_b'], [$a, $b]);
        app(\App\Tenancy\TenantContext::class)->activate('default');
        $this->assertSame($base, config('session.cookie'));
    }
}
