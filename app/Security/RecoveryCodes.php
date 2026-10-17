<?php

namespace App\Security;

/** SEC-12: one-time recovery codes for a lost authenticator. Shown once, stored only as keyed hashes, each usable once. */
final class RecoveryCodes
{
    public const COUNT = 10;

    /** @return array{0: list<string>, 1: list<string>} [plain codes to show once, hashes to store] */
    public static function generate(): array
    {
        $plain = [];
        for ($i = 0; $i < self::COUNT; $i++) {
            $plain[] = strtolower(substr(Totp::base32Encode(random_bytes(7)), 0, 5).'-'.substr(Totp::base32Encode(random_bytes(7)), 0, 5));
        }

        return [$plain, array_map([self::class, 'hash'], $plain)];
    }

    public static function hash(string $code): string
    {
        return hash_hmac('sha256', self::normalise($code), (string) config('app.key'));
    }

    public static function normalise(string $code): string
    {
        return strtolower(preg_replace('/[\s-]+/', '', $code));
    }

    /** Remove the matching hash and return the rest, or null when the code is not on the list. @param list<string> $hashes */
    public static function consume(array $hashes, string $code): ?array
    {
        $h = self::hash($code);
        $found = null;
        foreach ($hashes as $i => $stored) {
            if (hash_equals($stored, $h)) {
                $found = $i;
            }
        }
        if ($found === null) {
            return null;
        }
        unset($hashes[$found]);

        return array_values($hashes);
    }

    /** Looks like a recovery code rather than a 6-digit TOTP. */
    public static function looksLike(string $input): bool
    {
        return (bool) preg_match('/^[a-z2-7]{5}-?[a-z2-7]{5}$/i', trim($input));
    }
}
