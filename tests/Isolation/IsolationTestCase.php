<?php

namespace Tests\Isolation;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Hearing;
use App\Models\Task;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * TEN-10: base of the cross-tenant isolation suite (docs/ISOLATION_TESTING.md).
 *
 * Two firms are PROVISIONED through the real lifecycle (own database, migrations, roles, admin), then seeded with differently-sized,
 * canary-marked data, and exercised over real HTTP on their own hosts, in ONE process (like a long-lived worker), so that state which
 * is memoised across requests shows up as a leak. A canary is a string that exists only in one firm; if it ever appears in what the
 * other firm receives, that is a failure whatever the cause.
 *
 * Sizes differ on purpose (A: 2 clients, B: 5): ids that exist only in B must be unreachable from A, and ids that exist in both must
 * still return the caller's own row.
 */
abstract class IsolationTestCase extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    protected string $dir;

    protected string $originalDefault;

    /** @var array<string,array{tenant:Tenant,host:string,cookies:array,password:string,email:string}> */
    protected array $firm = [];

    /**
     * The shared infrastructure the suite runs on. Default: each firm a SQLite file, local disk, file sessions and cache.
     * The isolation workflow runs it again on the production drivers (docs/ISOLATION_TESTING.md):
     *  - ISOLATION_DB=mysql: each firm a database on one MySQL server (connection `mysql` from DB_HOST/DB_USERNAME/...);
     *  - ISOLATION_REDIS=1: cache and sessions in one Redis (connection from REDIS_HOST), separated only by the firm prefix;
     *  - ISOLATION_S3=1: DriversIsolationTest also puts both firms' files in one S3 bucket (disk `s3` from AWS_*; MinIO in
     *    CI), separated only by the key prefix (the rest of the suite inspects the local disk).
     */
    protected static function driver(string $name): bool
    {
        return match ($name) {
            'mysql' => getenv('ISOLATION_DB') === 'mysql',
            'redis' => (bool) getenv('ISOLATION_REDIS'),
            's3' => (bool) getenv('ISOLATION_S3'),
            default => false,
        };
    }

    /** Where a firm's database lives: a file in this test's directory, or a uniquely named database on the MySQL server. */
    protected function firmDatabase(string $slug): string
    {
        return static::driver('mysql') ? "iso_{$slug}_".substr(md5($this->dir), 0, 8) : "{$this->dir}/{$slug}.sqlite";
    }

    /** A connection to the MySQL server itself (phpunit.xml's DB_DATABASE is SQLite's ':memory:'). */
    protected function mysqlServer(): \Illuminate\Database\Connection
    {
        config(['database.connections.isolation_server' => ['database' => 'information_schema'] + (array) config('database.connections.mysql')]);

        return DB::connection('isolation_server');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/isolation-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => static::driver('mysql') ? 'mysql' : 'sqlite',
            'tenancy.storage_disks' => static::driver('s3') ? ['local', 's3'] : ['local'],
            'tenancy.platform_host' => 'admin.platform.test', 'tenancy.base_domain' => 'app.test', 'tenancy.platform_mfa' => 'optional',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        // Sessions are how the SPA authenticates, so they are exercised for real: the default driver (file), shared by every firm.
        @mkdir("{$this->dir}/sessions");
        config(['session.driver' => static::driver('redis') ? 'redis' : 'file', 'session.files' => "{$this->dir}/sessions", 'sanctum.stateful' => ['a.app.test', 'b.app.test']]);
        if (static::driver('redis')) {
            // one Redis for every firm, as in production: only the firm prefix keeps them apart
            config(['cache.default' => 'redis', 'session.connection' => 'default', 'database.redis.options.prefix' => 'iso'.substr(md5($this->dir), 0, 6).':']);
            app('redis')->purge();
            app('cache')->forgetDriver();
            app()->forgetInstance('cache.store');
        }

        // TenantContext captured the session settings when the application booted; point its base at this test's directory.
        $ctx = app(TenantContext::class);
        $ref = new \ReflectionProperty($ctx, 'baseSessionFiles');
        $ref->setValue($ctx, "{$this->dir}/sessions");

        foreach (['a' => 2, 'b' => 5] as $slug => $clients) {
            $this->provisionFirm($slug);
            $this->seedFirm($slug, $clients);
        }
        config(['tenancy.mode' => 'multi']);   // from here on every request is resolved by host, fail-closed
        // chosen at boot from the mode: rebuild now that the mode is multi (as the deployment would have booted)
        $this->app->forgetInstance(\App\Entitlements\EntitlementSource::class);
        $this->app->forgetInstance(\App\Entitlements\Entitlements::class);
        app(TenantManager::class)->failClosed();
        foreach (array_keys($this->firm) as $slug) {
            $this->firm[$slug]['cookies'] = $this->login($slug);
        }
    }

    protected function tearDown(): void
    {
        // files written to the real storage directories under these two firm keys
        foreach (['a', 'b'] as $k) {
            foreach ([storage_path("app/tenants/{$k}"), storage_path("framework/cache/data/{$k}")] as $d) {
                is_dir($d) && \Illuminate\Support\Facades\File::deleteDirectory($d);
            }
        }
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        if (static::driver('mysql')) {
            foreach (array_keys($this->firm) as $slug) {
                $this->mysqlServer()->statement('DROP DATABASE IF EXISTS `'.$this->firmDatabase($slug).'`');
            }
        }
        if (static::driver('redis')) {
            foreach (['default', 'cache'] as $c) {
                app('redis')->connection($c)->flushdb();   // the suite's own Redis databases (REDIS_DB, REDIS_CACHE_DB), never shared ones
            }
        }
        parent::tearDown();
    }

    protected function provisionFirm(string $slug): void
    {
        $r = app(TenantLifecycle::class)->provision([
            'slug' => $slug, 'name' => strtoupper($slug).' LLP', 'admin_email' => "admin@{$slug}.test", 'domain' => "{$slug}.app.test",
            'database' => $this->firmDatabase($slug), 'admin_password' => "Correct-horse-{$slug}-1",
        ]);
        // the local disk unless a test moves the firm (DriversIsolationTest puts both in one S3 bucket)
        $r['tenant']->forceFill(['files_disk' => 'local'])->save();
        $this->firm[$slug] = ['tenant' => $r['tenant'], 'host' => "http://{$slug}.app.test", 'password' => "Correct-horse-{$slug}-1", 'email' => "admin@{$slug}.test", 'cookies' => []];
    }

    protected function inFirm(string $slug, \Closure $fn): mixed
    {
        return app(TenantManager::class)->runIn($this->firm[$slug]['tenant'], $fn);
    }

    protected function canary(string $slug): string
    {
        return 'CANARY-'.strtoupper($slug);
    }

    private function seedFirm(string $slug, int $clients): void
    {
        $c = $this->canary($slug);
        $this->inFirm($slug, function () use ($clients, $c) {
            $made = [];
            for ($i = 1; $i <= $clients; $i++) {
                $made[] = Client::factory()->create(['name' => "{$c}-client-{$i}", 'address' => "{$c} street {$i}"]);
            }
            foreach ($made as $i => $client) {
                $case = Cases::factory()->create(['client_id' => $client->id, 'case_number' => "{$c}-CASE-{$i}", 'description' => "{$c} privileged description {$i}", 'opposing_party' => "{$c} opposing"]);
                Hearing::factory()->create(['case_id' => $case->id]);
                Task::factory()->create(['title' => "{$c} task {$i}"]);
            }
        });
    }

    /** A browser-like request: stateful (Referer/Origin of the host it is sent to), carrying the given cookies. */
    protected function http(string $host, string $method, string $uri, array $data = [], array $cookies = [], array $headers = []): TestResponse
    {
        $this->freshProcess();
        $server = ['HTTP_REFERER' => $host.'/', 'HTTP_ORIGIN' => $host, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $k => $v) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $k))] = $v;
        }
        // Raw call: the cookies are the exact (already encrypted) values the server issued, as a browser would send them back.
        $r = $this->call($method, $host.'/api/v1'.$uri, [], $cookies, [], $server, $data ? json_encode($data) : null);

        return $r;
    }

    /**
     * Production serves each request from a fresh PHP process; this suite serves them all from one. Laravel's session store keeps
     * its attributes in memory between requests, which would make a cookie-less request look logged in, so the session and login state
     * is dropped exactly as a new process would have none. Everything else (cache, config, singletons, memoised services) is kept on
     * purpose: a leak through those is what running in one process is meant to expose.
     */
    protected function freshProcess(): void
    {
        $this->flushHeaders();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('auth')->forgetGuards();
    }

    /** A multipart upload as a browser would send it. */
    protected function upload(string $slug, string $uri, array $fields, string $field, \Illuminate\Http\UploadedFile $file, ?string $cookiesOf = null): TestResponse
    {
        $this->freshProcess();
        $host = $this->firm[$slug]['host'];
        $server = ['HTTP_REFERER' => $host.'/', 'HTTP_ORIGIN' => $host, 'HTTP_ACCEPT' => 'application/json'];

        return $this->call('POST', $host.'/api/v1'.$uri, $fields, $this->firm[$cookiesOf ?? $slug]['cookies'], [$field => $file], $server);
    }

    /** Log in through the real endpoint and return the session cookies. */
    protected function login(string $slug): array
    {
        $r = $this->http($this->firm[$slug]['host'], 'POST', '/login', ['email' => $this->firm[$slug]['email'], 'password' => $this->firm[$slug]['password']])->assertOk();

        return collect($r->headers->getCookies())->mapWithKeys(fn ($c) => [$c->getName() => $c->getValue()])->all();
    }

    /** One call to a firm's own host as that firm's admin (or with $cookiesOf's session). */
    protected function api(string $slug, string $method, string $uri, array $data = [], ?string $cookiesOf = null, array $headers = []): TestResponse
    {
        return $this->http($this->firm[$slug]['host'], $method, $uri, $data, $this->firm[$cookiesOf ?? $slug]['cookies'], $headers);
    }

    protected function anonymous(string $slug, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->http($this->firm[$slug]['host'], $method, $uri, $data, [], $headers);
    }

    /** The other firm's canary must not be anywhere in what this response carries (body or headers). */
    protected function assertNoLeak(TestResponse $r, string $ofSlug, string $context = ''): void
    {
        $needle = strtolower($this->canary($ofSlug));
        $haystack = strtolower($r->getContent().json_encode($r->headers->all()));
        $this->assertStringNotContainsString($needle, $haystack, "{$context}: firm {$ofSlug}'s data leaked into another firm's response");
    }

    /** Ids that exist in B but not in A (B has more rows). */
    protected function idsOnlyIn(string $slug, string $table, string $notIn): array
    {
        $mine = $this->inFirm($slug, fn () => DB::table($table)->pluck('id')->all());
        $theirs = $this->inFirm($notIn, fn () => DB::table($table)->pluck('id')->all());

        return array_values(array_diff($mine, $theirs));
    }

    /** A real, persistent store (production uses file/redis/database): the array store forgets everything on a firm switch by design. */
    protected function persistentCache(): void
    {
        config(['cache.default' => 'file']);
        app('cache')->forgetDriver();
        app()->forgetInstance('cache.store');
    }
}
