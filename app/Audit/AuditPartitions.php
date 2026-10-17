<?php

namespace App\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DATA-11: monthly RANGE partitions on `audits` (MySQL / MariaDB only; other drivers report "unsupported").
 *
 * enable() rebuilds the table once (primary key becomes (id, created_at), as MySQL requires the partition column in
 * every unique key) with one partition per month from the oldest row to two months ahead, plus a catch-all. ensure()
 * keeps two future months available, and the archiver drops a month's partition instead of deleting row by row.
 */
class AuditPartitions
{
    public const TABLE = 'audits';

    public function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function enabled(): bool
    {
        return $this->supported() && $this->names() !== [];
    }

    /** @return list<string> partition names, e.g. p202608, pmax */
    public function names(): array
    {
        if (! $this->supported()) {
            return [];
        }

        return DB::table('information_schema.PARTITIONS')
            ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())->where('TABLE_NAME', self::TABLE)
            ->whereNotNull('PARTITION_NAME')->orderBy('PARTITION_ORDINAL_POSITION')->pluck('PARTITION_NAME')->all();
    }

    public function enable(): void
    {
        if (! $this->supported()) {
            throw new RuntimeException('Monthly partitions need MySQL or MariaDB.');
        }
        if ($this->enabled()) {
            return;
        }
        $t = self::TABLE;
        DB::statement("UPDATE {$t} SET created_at = COALESCE(updated_at, CURRENT_TIMESTAMP) WHERE created_at IS NULL");
        DB::statement("ALTER TABLE {$t} MODIFY created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
        DB::statement("ALTER TABLE {$t} DROP PRIMARY KEY, ADD PRIMARY KEY (id, created_at)");

        $oldest = DB::table($t)->min('created_at');
        $from = CarbonImmutable::parse($oldest ?? 'now')->startOfMonth();
        $parts = [];
        for ($m = $from; $m <= CarbonImmutable::now()->startOfMonth()->addMonths(2); $m = $m->addMonth()) {
            $parts[] = $this->definition($m);
        }
        $parts[] = 'PARTITION pmax VALUES LESS THAN MAXVALUE';
        DB::statement("ALTER TABLE {$t} PARTITION BY RANGE (UNIX_TIMESTAMP(created_at)) (".implode(', ', $parts).')');
    }

    /** Keep partitions for this month and the next two (split from the catch-all). */
    public function ensure(): int
    {
        if (! $this->enabled()) {
            return 0;
        }
        $have = array_flip($this->names());
        $added = 0;
        for ($m = CarbonImmutable::now()->startOfMonth(); $m <= CarbonImmutable::now()->startOfMonth()->addMonths(2); $m = $m->addMonth()) {
            if (! isset($have[self::name($m)])) {
                DB::statement('ALTER TABLE '.self::TABLE.' REORGANIZE PARTITION pmax INTO ('.$this->definition($m).', PARTITION pmax VALUES LESS THAN MAXVALUE)');
                $added++;
            }
        }

        return $added;
    }

    /**
     * Remove one month's rows at once. Only when that month's partition holds exactly $expectedRows rows (the ones just
     * archived): a late row with an old timestamp, or a time-zone mismatch on the boundary, makes it decline, and the
     * caller deletes row by row instead. Returns false when it declined.
     */
    public function dropMonth(CarbonImmutable $month, int $expectedRows): bool
    {
        $name = self::name($month);
        if (! in_array($name, $this->names(), true)) {
            return false;
        }
        if ((int) DB::selectOne('SELECT COUNT(*) AS n FROM '.self::TABLE." PARTITION ({$name})")->n !== $expectedRows) {
            return false;
        }
        DB::statement('ALTER TABLE '.self::TABLE.' DROP PARTITION '.self::name($month));

        return true;
    }

    public static function name(CarbonImmutable $month): string
    {
        return 'p'.$month->format('Ym');
    }

    private function definition(CarbonImmutable $month): string
    {
        $end = $month->startOfMonth()->addMonth()->getTimestamp();

        return 'PARTITION '.self::name($month)." VALUES LESS THAN ({$end})";
    }
}
