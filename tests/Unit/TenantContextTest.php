<?php

namespace Tests\Unit;

use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_cache_and_permission_cache_keys_differ_per_tenant(): void
    {
        // The array driver forgets everything when its store is rebuilt, which
        // would make this test vacuous — use a real, persistent driver.
        $path = sys_get_temp_dir().'/tenant-cache-'.uniqid();
        config(['cache.default' => 'file', 'cache.stores.file.path' => $path]);
        app('cache')->forgetDriver();

        app()->forgetInstance(TenantContext::class);
        $ctx = app(TenantContext::class);

        $ctx->activate('firm-a');
        $keyA = config('permission.cache.key');
        $prefixA = config('cache.prefix');
        Cache::put('shared-name', 'A-value', 60);

        $ctx->activate('firm-b');
        $this->assertNotSame($keyA, config('permission.cache.key'));
        $this->assertNotSame($prefixA, config('cache.prefix'));
        $this->assertStringContainsString('firm-b', config('permission.cache.key'));
        $this->assertNull(Cache::get('shared-name'), 'tenant B must not read tenant A cache entries');

        $ctx->activate('firm-a');
        $this->assertSame('A-value', Cache::get('shared-name'));

        (new \Illuminate\Filesystem\Filesystem)->deleteDirectory($path);
    }

    public function test_storage_prefix_carries_tenant_key(): void
    {
        $ctx = app(TenantContext::class);
        $ctx->activate('firm-a');

        $this->assertSame('tenants/firm-a/cases/docs', $ctx->storagePrefix('cases/docs'));
    }

    public function test_invalid_tenant_key_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(TenantContext::class)->activate('../other');
    }
}
