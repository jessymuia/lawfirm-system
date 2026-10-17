<?php

namespace App\Tenancy;

use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * TEN-4: what a request pays to reach a cell's database. PHP opens a new connection per request (no persistent
 * connections), so the connect time is paid on every request, then a few round trips. Measured per sample:
 *  - connect: TCP + handshake + authentication;
 *  - query:   one `SELECT 1` round trip on that connection;
 *  - request: connect + 5 small queries + disconnect (a typical API request's database part).
 * Comparing a cell's endpoint (e.g. a connection proxy) with the server behind it gives the proxy's cost.
 */
class CellLatency
{
    public function __construct(private ConnectionFactory $factory)
    {
    }

    /**
     * @param  array<string, mixed>  $server  a connection config without a database (CellRegistry::serverConfig)
     * @return array{connect: array<string, float>, query: array<string, float>, request: array<string, float>, errors: int}
     */
    public function measure(array $server, int $samples = 50): array
    {
        $config = $server + ['database' => $server['database'] ?? (($server['driver'] ?? '') === 'sqlite' ? ':memory:' : 'information_schema')];
        $connector = $this->factory->createConnector($config);
        $connect = $query = $request = [];
        $errors = 0;
        try {
            $connector->connect($config);   // warm DNS and the server's thread cache, as a running app would have them
        } catch (\Throwable) {
            // counted below, per sample
        }

        for ($i = 0; $i < $samples; $i++) {
            try {
                $t0 = hrtime(true);
                $pdo = $connector->connect($config);
                $t1 = hrtime(true);
                $pdo->query('SELECT 1')->fetchColumn();
                $t2 = hrtime(true);
                for ($q = 0; $q < 4; $q++) {
                    $pdo->query('SELECT 1')->fetchColumn();
                }
                $pdo = null;
                $t3 = hrtime(true);
                $connect[] = ($t1 - $t0) / 1e6;
                $query[] = ($t2 - $t1) / 1e6;
                $request[] = ($t3 - $t0) / 1e6;
            } catch (\Throwable) {
                $errors++;
            }
        }

        return ['connect' => self::stats($connect), 'query' => self::stats($query), 'request' => self::stats($request), 'errors' => $errors];
    }

    /** @param  list<float>  $ms  @return array<string, float> */
    public static function stats(array $ms): array
    {
        if ($ms === []) {
            return ['p50' => 0.0, 'p95' => 0.0, 'max' => 0.0, 'mean' => 0.0];
        }
        sort($ms);
        $at = fn (float $p) => $ms[min(count($ms) - 1, (int) ceil($p * count($ms)) - 1)];

        return ['p50' => round($at(0.5), 3), 'p95' => round($at(0.95), 3), 'max' => round(end($ms), 3), 'mean' => round(array_sum($ms) / count($ms), 3)];
    }
}
