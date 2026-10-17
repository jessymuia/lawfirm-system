<?php

namespace App\Conflicts;

use Illuminate\Support\Str;

/**
 * FUN-9: how two names are compared. "Wanjiru & Co. Ltd", "WANJIRU AND COMPANY LIMITED" and "wanjiru co" must meet;
 * "Kamau" must not match every "Kamau ..." in the firm, but "John Kamau" must find "Kamau John" and "John K. Kamau".
 */
final class Names
{
    /** Words that say what kind of entity it is, not who: dropped before comparing. */
    private const NOISE = ['the', 'and', 'of', 'ltd', 'limited', 'plc', 'llc', 'llp', 'inc', 'incorporated', 'co', 'company',
        'corp', 'corporation', 'group', 'holdings', 'hldgs', 'mr', 'mrs', 'ms', 'miss', 'dr', 'prof', 'hon', 'eng', 'adv', 'advocate', 'advocates'];

    public static function normalize(?string $name): string
    {
        $s = Str::lower(Str::ascii((string) $name));
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $tokens = array_values(array_filter(explode(' ', (string) $s), fn ($t) => $t !== '' && ! in_array($t, self::NOISE, true)));

        return implode(' ', $tokens);
    }

    /** National IDs / registration numbers: digits and letters only. */
    public static function identifier(?string $id): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $id));
    }

    /** Phone numbers: the last 9 digits (07xx… and +2547xx… are the same line). */
    public static function phone(?string $phone): string
    {
        $digits = (string) preg_replace('/\D/', '', (string) $phone);

        return strlen($digits) >= 9 ? substr($digits, -9) : '';
    }

    /**
     * How strongly two normalised names match: 1.0 identical, else a score from token overlap and spelling distance.
     * 0 when they should not be reported. A single short word on either side never matches on its own.
     */
    public static function score(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $ta = array_values(array_unique(explode(' ', $a)));
        $tb = array_values(array_unique(explode(' ', $b)));
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];

        // every word of the shorter name appears (or nearly appears) in the longer one, in any order
        $found = 0;
        foreach ($short as $t) {
            foreach ($long as $u) {
                if ($t === $u || (strlen($t) >= 5 && strlen($u) >= 5 && levenshtein($t, $u) <= 1)) {
                    $found++;
                    break;
                }
            }
        }
        $meaningful = count($short) >= 2 || strlen($short[0]) >= 6;
        if ($found === count($short) && $meaningful) {
            return round(0.75 + 0.2 * (count($short) / count($long)), 2);
        }

        // close spelling of the whole name (typos, transliteration)
        $max = max(strlen($a), strlen($b));
        if ($max >= 8) {
            $similarity = 1 - levenshtein(substr($a, 0, 255), substr($b, 0, 255)) / $max;
            if ($similarity >= 0.85) {
                return round($similarity * 0.9, 2);
            }
        }

        return 0.0;
    }
}
