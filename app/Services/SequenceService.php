<?php

namespace App\Services;

use App\Support\FirmSettings;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * DATA-8: concurrency-safe, per-tenant sequential numbering.
 *
 * Replaces MAX(id)+1, where two concurrent creates read the same value and
 * one fails on the unique index. Each (name, scope) row is locked with
 * SELECT ... FOR UPDATE for the duration of the caller's transaction.
 *
 * Gap-free behaviour: the lock is released when the *outermost* transaction
 * commits. Call next() inside the same transaction that inserts the
 * numbered record so a rollback also rolls the counter back. Called outside
 * a transaction it is still collision-free, but a failed insert leaves a gap
 * (auditable: compare last_value with the highest issued number).
 */
class SequenceService
{
    /**
     * @param  string  $name  Sequence name, e.g. 'invoice'.
     * @param  string|int|null  $scope  Optional reset scope, e.g. the year.
     * @param  Closure|null  $seed  Returns the starting last_value the first
     *                              time this sequence is used (to continue
     *                              from pre-existing data).
     */
    public function next(string $name, string|int|null $scope = null, ?Closure $seed = null): int
    {
        $scope = (string) ($scope ?? '');

        return DB::transaction(function () use ($name, $scope, $seed) {
            // Create the counter row BEFORE any locking read. A `FOR UPDATE`
            // on a row that does not exist yet takes an InnoDB gap lock, and
            // two first-time callers then deadlock on their inserts.
            // The no-op upsert (not insertOrIgnore) tolerates a concurrent
            // creator: on a duplicate key it takes an EXCLUSIVE lock at once,
            // so the loser simply waits. insertOrIgnore leaves a SHARED lock
            // on the row, and two such callers then deadlock upgrading it.
            $exists = DB::table('sequences')->where('name', $name)->where('scope', $scope)->exists();
            if (! $exists) {
                DB::table('sequences')->upsert([
                    'name' => $name,
                    'scope' => $scope,
                    'last_value' => $seed ? (int) $seed() : 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], ['name', 'scope'], ['name']);
            }

            $row = $this->lockedRow($name, $scope);

            $next = ((int) $row->last_value) + 1;

            DB::table('sequences')->where('id', $row->id)->update([
                'last_value' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        });
    }

    /**
     * Next human-readable number such as INV-000123 (or INV-2026-000123 when
     * per-year numbering is on). On first use the counter continues from the
     * highest number already present in $table.$column.
     */
    public function nextFormatted(string $name, string $prefix, string $table, string $column): string
    {
        // The firm's own setting (Settings, Currencies and tax, Numbering) wins; the environment switch is the default for everyone else.
        $perYear = (bool) FirmSettings::get('numbering.per_year', (bool) config('invoicing.number_per_year', false));
        $custom = FirmSettings::get("numbering.prefix.{$name}");
        if (is_string($custom) && preg_match('/^[A-Z0-9]{1,8}$/', $custom)) {
            $prefix = $custom;
        }
        $year = now()->year;
        $fullPrefix = $perYear ? "{$prefix}-{$year}-" : "{$prefix}-";

        $n = $this->next($name, $perYear ? $year : null, function () use ($table, $column, $fullPrefix) {
            $max = 0;
            DB::table($table)->where($column, 'like', $fullPrefix.'%')->pluck($column)->each(function ($v) use (&$max, $fullPrefix) {
                if (preg_match('/^'.preg_quote($fullPrefix, '/').'(\d+)$/', (string) $v, $m)) {
                    $max = max($max, (int) $m[1]);
                }
            });

            return $max;
        });

        return $fullPrefix.str_pad((string) $n, 6, '0', STR_PAD_LEFT);
    }

    private function lockedRow(string $name, string $scope): ?object
    {
        return DB::table('sequences')
            ->where('name', $name)
            ->where('scope', $scope)
            ->lockForUpdate()
            ->first();
    }
}
