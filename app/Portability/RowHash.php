<?php

namespace App\Portability;

/**
 * PLAT-9: an order-independent checksum of a table's rows that is the same whether the rows come from the source DB,
 * the bundle file, or the target DB after import: sha256( count | XOR of each row's sha256 ).
 * Values are normalised so 12.5 / "12.50" / 12.50 compare equal across engines; everything else is compared exactly.
 */
final class RowHash
{
    private string $acc;

    private int $count = 0;

    /** @param list<string> $ignore columns left out of the hash */
    public function __construct(private array $ignore = [])
    {
        $this->acc = str_repeat("\0", 32);
    }

    public function add(array $row): void
    {
        foreach ($this->ignore as $c) {
            unset($row[$c]);
        }
        ksort($row);
        $norm = [];
        foreach ($row as $k => $v) {
            $norm[$k] = self::normalise($v);
        }
        $this->acc ^= hash('sha256', json_encode($norm, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
        $this->count++;
    }

    public function count(): int
    {
        return $this->count;
    }

    public function result(): string
    {
        return hash('sha256', $this->count.'|'.bin2hex($this->acc));
    }

    public static function normalise(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v ? '1' : '0';
        }
        $s = is_float($v) ? rtrim(rtrim(sprintf('%.12F', $v), '0'), '.') : (string) $v;
        if (is_numeric($s) && str_contains($s, '.') && ! str_contains(strtolower($s), 'e')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '' || $s === '-0' ? ($s === '' ? '' : '0') : $s;
    }
}
