<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TEN-6: every cache entry, lock and rate-limit counter is namespaced per firm by the framework (TenantContext rewrites
 * `cache.prefix` / the file-cache directory on activation), not by each call site. That only holds while code goes
 * through the Cache repository. This static check fails the build on the ways around it: talking to the store
 * directly, building a repository by hand, or giving a store its own fixed prefix in config.
 *
 * A genuine exception needs `// tenant-prefix: allow -- <reason>` on the same line.
 */
class CacheNamespacingRulesTest extends TestCase
{
    /** pattern => why it bypasses the firm prefix */
    private const FORBIDDEN = [
        '/\bRedis::/' => 'the Redis facade writes raw keys with no cache prefix; use Cache::',
        '/->getStore\(\)/' => 'the underlying store is unprefixed; use the repository',
        '/new\s+\\\\?(Illuminate\\\\Cache\\\\)?Repository\b/' => 'a hand-built repository does not follow the firm prefix',
        '/\b(apcu_\w+|Memcached)\b/' => 'direct APCu/Memcached access bypasses the cache prefix',
        "/DB::table\\(\\s*'cache(_locks)?'\\s*\\)/" => 'the cache tables are only to be read through the cache store',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    public function test_application_code_never_bypasses_the_firm_cache_prefix(): void
    {
        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root().'/app'));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (str_contains($line, 'tenant-prefix: allow --')) {
                    continue;
                }
                $code = preg_replace('#//.*$|^\s*\*.*$#', '', $line);   // ignore comments
                foreach (self::FORBIDDEN as $re => $why) {
                    if (preg_match($re, (string) $code)) {
                        $offenders[] = str_replace($this->root().'/', '', $file->getPathname()).':'.($n + 1)." — {$why}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Cache access that would not be namespaced per firm:\n".implode("\n", $offenders));
    }

    public function test_no_cache_store_overrides_the_shared_prefix(): void
    {
        $config = file_get_contents($this->root().'/config/cache.php');
        $stores = substr($config, strpos($config, "'stores'"), strpos($config, "'prefix' =>", strpos($config, "'stores'")) - strpos($config, "'stores'"));

        $this->assertDoesNotMatchRegularExpression("/'prefix'\\s*=>/", $stores,
            'A store with its own prefix ignores cache.prefix, which is where TenantContext puts the firm.');
    }

    public function test_the_rules_catch_what_they_claim_to(): void
    {
        $samples = [
            "Redis::set('k', 1);", '$x = Cache::store()->getStore();', '$r = new Repository($s);',
            '$r = new \\Illuminate\\Cache\\Repository($s);', 'apcu_store("k", 1);', "DB::table('cache_locks')->delete();",
        ];
        foreach ($samples as $code) {
            $hit = false;
            foreach (array_keys(self::FORBIDDEN) as $re) {
                $hit = $hit || preg_match($re, $code);
            }
            $this->assertTrue($hit, "not caught: {$code}");
        }
        foreach (["Cache::put('k', 1);", "Cache::lock('k')->get();", "RateLimiter::hit('k');"] as $ok) {
            foreach (array_keys(self::FORBIDDEN) as $re) {
                $this->assertDoesNotMatchRegularExpression($re, $ok);
            }
        }
    }
}
