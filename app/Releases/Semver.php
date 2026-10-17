<?php

namespace App\Releases;

/** PLAT-10: the small part of semver 2.0 the release tooling needs (no dependency on a dev-only package). */
final class Semver
{
    private const RE = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?$/';

    /** @return array{0:int,1:int,2:int,3:?array}|null */
    public static function parse(string $v): ?array
    {
        if (! preg_match(self::RE, trim($v), $m)) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3], ($m[4] ?? '') === '' ? null : explode('.', $m[4])];
    }

    public static function valid(string $v): bool
    {
        return self::parse($v) !== null;
    }

    public static function isPrerelease(string $v): bool
    {
        return (self::parse($v)[3] ?? null) !== null;
    }

    /** -1, 0, 1. A prerelease sorts before its release; numeric identifiers sort before alphanumeric ones. */
    public static function compare(string $a, string $b): int
    {
        $x = self::parse($a) ?? throw new \InvalidArgumentException("Not a semantic version: {$a}");
        $y = self::parse($b) ?? throw new \InvalidArgumentException("Not a semantic version: {$b}");
        for ($i = 0; $i < 3; $i++) {
            if ($x[$i] !== $y[$i]) {
                return $x[$i] <=> $y[$i];
            }
        }
        if ($x[3] === null || $y[3] === null) {
            return ($x[3] === null ? 1 : 0) <=> ($y[3] === null ? 1 : 0);
        }
        foreach (array_map(null, $x[3], $y[3]) as [$p, $q]) {
            if ($p === null || $q === null) {
                return ($p === null ? 0 : 1) <=> ($q === null ? 0 : 1);
            }
            $pn = ctype_digit($p);
            $qn = ctype_digit($q);
            if ($pn && $qn) {
                if ((int) $p !== (int) $q) {
                    return (int) $p <=> (int) $q;
                }
            } elseif ($pn !== $qn) {
                return $pn ? -1 : 1;
            } elseif ($p !== $q) {
                return strcmp($p, $q) <=> 0;
            }
        }

        return 0;
    }

    /**
     * The biggest numeric component that differs: major | minor | patch | none (same x.y.z, e.g. 1.0.0-rc.1 -> 1.0.0) | downgrade.
     */
    public static function bump(string $from, string $to): string
    {
        if (self::compare($from, $to) > 0) {
            return 'downgrade';
        }
        $x = self::parse($from);
        $y = self::parse($to);

        return match (true) {
            $x[0] !== $y[0] => 'major',
            $x[1] !== $y[1] => 'minor',
            $x[2] !== $y[2] => 'patch',
            default => 'none',
        };
    }
}
