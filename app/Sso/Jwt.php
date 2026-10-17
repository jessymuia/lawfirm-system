<?php

namespace App\Sso;

/**
 * SEC-12: verifies an OIDC ID token. RS256 only: `none`, HS256 (the classic key-confusion attack) and everything else
 * are refused outright. The key comes from the provider's JWKS by `kid`; claims are checked by OidcClient.
 */
final class Jwt
{
    /** @param array<string,mixed> $jwks a JWKS document as fetched from the IdP (may be malformed)
     *  @return array<string,mixed> the verified claims */
    public static function verify(string $jwt, array $jwks): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new SsoException('Malformed token.');
        }
        [$h64, $p64, $s64] = $parts;
        $header = json_decode(self::b64d($h64), true);
        $claims = json_decode(self::b64d($p64), true);
        if (! is_array($header) || ! is_array($claims)) {
            throw new SsoException('Malformed token.');
        }
        if (($header['alg'] ?? null) !== 'RS256') {
            throw new SsoException('Unsupported token algorithm.');
        }
        $key = null;
        foreach ((array) ($jwks['keys'] ?? []) as $k) {
            if (($k['kty'] ?? null) === 'RSA' && ($k['use'] ?? 'sig') === 'sig' && ($k['kid'] ?? null) === ($header['kid'] ?? null) && isset($k['n'], $k['e'])) {
                $key = $k;
            }
        }
        if (! $key) {
            throw new SsoException('No matching signing key.');
        }
        $pem = self::rsaPem(self::b64d($key['n']), self::b64d($key['e']));
        if (openssl_verify($h64.'.'.$p64, self::b64d($s64), $pem, OPENSSL_ALGO_SHA256) !== 1) {
            throw new SsoException('Bad token signature.');
        }

        return $claims;
    }

    public static function b64d(string $s): string
    {
        $out = base64_decode(strtr($s, '-_', '+/'), true);
        if ($out === false) {
            throw new SsoException('Malformed token.');
        }

        return $out;
    }

    public static function b64e(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** RSA public key (modulus, exponent) as a PEM SubjectPublicKeyInfo. */
    public static function rsaPem(string $n, string $e): string
    {
        $int = function (string $b): string {
            $b = ltrim($b, "\0");
            if ($b === '') {
                $b = "\0";
            }
            if (ord($b[0]) > 0x7F) {
                $b = "\0".$b;   // keep the INTEGER positive
            }

            return "\x02".self::len(strlen($b)).$b;
        };
        $seq = fn (string $b) => "\x30".self::len(strlen($b)).$b;
        $rsaKey = $seq($int($n).$int($e));
        $algo = $seq("\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01\x05\x00");
        $bits = "\x03".self::len(strlen($rsaKey) + 1)."\x00".$rsaKey;
        $der = $seq($algo.$bits);

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n").'-----END PUBLIC KEY-----';
    }

    private static function len(int $n): string
    {
        if ($n < 128) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\0");

        return chr(0x80 | strlen($b)).$b;
    }
}
