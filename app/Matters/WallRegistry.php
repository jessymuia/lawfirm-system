<?php

namespace App\Matters;

use Illuminate\Support\Facades\DB;

/**
 * SEC-11: the active ethical screens per user, loaded once per request/job (registered `scoped`) and flushed whenever a screen
 * changes. Query-builder only, so it can be consulted from inside global scopes without recursing.
 */
class WallRegistry
{
    /** @var array<int, array{cases: array<int,int>, clients: array<int,int>}> screen ids keyed by walled case/client id */
    private array $memo = [];

    /** @return array{cases: array<int,int>, clients: array<int,int>} */
    public function for(int $userId): array
    {
        return $this->memo[$userId] ??= $this->load($userId);
    }

    public function any(int $userId): bool
    {
        $w = $this->for($userId);

        return $w['cases'] !== [] || $w['clients'] !== [];
    }

    public function flush(): void
    {
        $this->memo = [];
    }

    private function load(int $userId): array
    {
        $out = ['cases' => [], 'clients' => []];
        $rows = DB::table('ethical_screens')->where('user_id', $userId)->whereNull('lifted_at')->whereNull('deleted_at')->get(['id', 'case_id', 'client_id']);
        foreach ($rows as $r) {
            if ($r->case_id) {
                $out['cases'][(int) $r->case_id] = (int) $r->id;
            }
            if ($r->client_id) {
                $out['clients'][(int) $r->client_id] = (int) $r->id;
            }
        }

        return $out;
    }
}
