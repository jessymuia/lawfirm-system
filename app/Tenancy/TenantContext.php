<?php

namespace App\Tenancy;

use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Facade;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * TEN-6 / TEN-12: the single place that knows "which tenant is active".
 *
 * Everything that must never be shared across tenants derives its namespace
 * from here: cache prefix, Spatie permission cache key, object-storage
 * prefix. activate() rewrites those framework settings at runtime, so
 * isolation is a framework-level default rather than a per-call convention.
 *
 * Fail closed: an invalid key throws instead of falling back to a default.
 */
class TenantContext
{
    private string $key;

    private string $baseCachePrefix;

    private string $basePermissionKey;

    /** @var array<string, string> Original path of each file-driver store. */
    private array $baseFilePaths = [];

    private string $baseSessionCookie;

    private string $baseSessionFiles;

    public function __construct()
    {
        $this->baseCachePrefix = (string) config('cache.prefix');
        $this->basePermissionKey = (string) config('permission.cache.key');

        foreach ((array) config('cache.stores') as $name => $store) {
            if (($store['driver'] ?? null) === 'file') {
                $this->baseFilePaths[$name] = (string) $store['path'];
            }
        }
        $this->baseSessionCookie = (string) config('session.cookie');
        $this->baseSessionFiles = (string) config('session.files');
        $this->key = self::validate((string) config('tenancy.key', 'default'));
    }

    public function key(): string
    {
        return $this->key;
    }

    /** Switch the active tenant and re-namespace cache + permission cache. */
    public function activate(string $key): void
    {
        $changed = $key !== $this->key;
        $this->key = self::validate($key);

        config([
            'cache.prefix' => $this->baseCachePrefix.$this->key.'_',
            'permission.cache.key' => $this->basePermissionKey.'.'.$this->key,
        ]);

        // The file driver ignores cache.prefix entirely, so it would be shared
        // across tenants. Give each tenant its own directory instead.
        foreach ($this->baseFilePaths as $name => $path) {
            config(["cache.stores.{$name}.path" => rtrim($path, '/').'/'.$this->key]);
        }

        // Drop already-resolved cache stores so they pick up the new prefix.
        app('cache')->forgetDriver();

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->initializeCache();
        }

        // TEN-12: object storage follows the firm too (scoped credentials when a storage role is configured).
        app(TenantStorageCredentials::class)->apply($this->key);

        if ($changed) {
            $this->rebindSessionAndAuth();
        }
    }

    /**
     * TEN-10: sessions are per firm as well. Each firm gets its own session cookie name and (file driver) its own directory, and
     * everything that memoised the previous firm's session or login is dropped, which matters in any process that outlives a request.
     * The default (single-firm) key keeps Laravel's original names.
     */
    private function rebindSessionAndAuth(): void
    {
        $default = $this->key === 'default';
        config([
            'session.cookie' => $default ? $this->baseSessionCookie : $this->baseSessionCookie.'_'.$this->key,
            'session.files' => $default ? $this->baseSessionFiles : rtrim($this->baseSessionFiles, '/').'/'.$this->key,
        ]);
        if (! $default && config('session.driver') === 'file' && ! is_dir((string) config('session.files'))) {
            @mkdir((string) config('session.files'), 0755, true);
        }
        if (app()->bound('session')) {
            app('session')->forgetDrivers();
        }
        app()->forgetInstance('session.store');
        if (app()->bound('auth')) {
            app('auth')->forgetGuards();
        }
        $this->refreshProcessWideCacheBindings();
        if (app()->bound(PermissionRegistrar::class)) {
            // the registry keeps the permission list in memory; without this, firm B would be judged by firm A's permission ids
            app(PermissionRegistrar::class)->clearPermissionsCollection();
        }
    }

    /**
     * Singletons that captured a cache repository (and so the previous firm's key prefix): the shared cache store binding and the
     * rate limiter. A request-per-process deployment never notices; a queue worker or any long-lived process would let firm A's
     * failed-login counters lock firm B out. The rate limiter's named limiters (RateLimiter::for) are carried over.
     */
    private function refreshProcessWideCacheBindings(): void
    {
        $limiters = [];
        if (app()->resolved(RateLimiter::class)) {
            $old = app(RateLimiter::class);
            $limiters = (new \ReflectionProperty($old, 'limiters'))->getValue($old);
        }
        foreach (['cache.store', 'cache.psr6', RateLimiter::class] as $abstract) {
            app()->forgetInstance($abstract);
        }
        Facade::clearResolvedInstance(RateLimiter::class);
        if ($limiters !== []) {
            $new = app(RateLimiter::class);
            foreach ($limiters as $name => $callback) {
                $new->for($name, $callback);
            }
        }
    }

    /** Object-storage prefix all tenant files must live under (TEN-12). */
    public function storagePrefix(string $path = ''): string
    {
        $base = 'tenants/'.$this->key;

        return $path === '' ? $base : $base.'/'.ltrim($path, '/');
    }

    private static function validate(string $key): string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $key)) {
            throw new InvalidArgumentException('Invalid tenant key.');
        }

        return $key;
    }
}
