<?php

namespace App\Tenancy;

use Illuminate\Cache\RedisStore;

/**
 * TEN-6 / TEN-10: a Redis cache store whose flush() removes only the active firm's keys.
 *
 * Laravel's RedisStore::flush() runs FLUSHDB, so on one Redis shared by every firm a single `cache:clear` (or
 * Cache::flush()) run for one firm emptied every firm's cache and locks, and their sessions when they share the
 * database. Every key this store writes starts with its prefix (TenantContext puts the firm in it), so flush() deletes
 * exactly the keys under that prefix, in batches, with SCAN (never KEYS). An empty prefix keeps Laravel's behaviour.
 * Found by running the isolation suite on Redis (DriversIsolationTest).
 */
class TenantRedisStore extends RedisStore
{
    public function flush()
    {
        if ($this->getPrefix() === '') {
            return parent::flush();
        }
        $client = $this->connection()->client();

        if ($client instanceof \Redis) {
            // phpredis adds the connection prefix to commands, not to SCAN's pattern or results
            $global = (string) $client->getOption(\Redis::OPT_PREFIX);   // tenant-prefix: allow -- reads the connection prefix to scope the flush to this firm
            $iterator = null;
            do {
                $keys = $client->scan($iterator, $global.$this->getPrefix().'*', 1000);
                if (is_array($keys) && $keys !== []) {
                    $client->del(array_map(fn ($k) => substr($k, strlen($global)), $keys));
                }
            } while (! empty($iterator));

            return true;
        }

        // Predis prefixes SCAN's pattern itself and returns full keys
        $global = (string) ($client->getOptions()->prefix?->getPrefix() ?? '');
        $cursor = 0;
        do {
            [$cursor, $keys] = $client->scan($cursor, ['match' => $this->getPrefix().'*', 'count' => 1000]);
            if ($keys !== []) {
                $client->del(array_map(fn ($k) => str_starts_with($k, $global) ? substr($k, strlen($global)) : $k, $keys));
            }
        } while ((string) $cursor !== '0');

        return true;
    }
}
