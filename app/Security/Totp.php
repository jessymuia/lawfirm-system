<?php

namespace App\Security;

/**
 * SEC-12: RFC 6238 time-based one-time passwords (HMAC-SHA1, 30 s step, 6 digits: what every authenticator app speaks).
 * Checked against the RFC's own test vectors. verify() returns the matched time step so a caller can refuse a replay
 * (the same code used twice inside its validity window).
 */
final class Totp
{
    public const STEP = 30;

    public const DIGITS = 6;

    private const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $base32Secret, int $step, int $digits = self::DIGITS): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($base32Secret), true);
        $o = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$o]) & 0x7F) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);

        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    public static function currentStep(?int $at = null): int
    {
        return intdiv($at ?? now()->timestamp, self::STEP);
    }

    /**
     * @param  int|null  $lastUsedStep  steps at or before this are refused (replay)
     * @return int|null the matched step, or null when the code is wrong/stale/replayed
     */
    public static function verify(string $base32Secret, string $code, ?int $lastUsedStep = null, int $window = 1, ?int $at = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $now = self::currentStep($at);
        $matched = null;
        for ($i = -$window; $i <= $window; $i++) {
            $step = $now + $i;
            // compare every candidate (no early exit) so timing does not reveal which offset matched
            if (hash_equals(self::code($base32Secret, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $matched = $matched === null || $step > $matched ? $step : $matched;
            }
        }

        return $matched;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account)
            .'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits='.self::DIGITS.'&period='.self::STEP;
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($b32, '='))) as $c) {
            $p = strpos(self::B32, $c);
            if ($p === false) {
                throw new \InvalidArgumentException('Not base32.');
            }
            $bits .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
