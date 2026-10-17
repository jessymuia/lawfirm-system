<?php

namespace App\Tenancy\Keys;

use RuntimeException;

/**
 * TEN-13: a firm's backup files are encrypted with its data key (libsodium secretstream, XChaCha20-Poly1305, 1 MiB
 * chunks), so destroying the key on purge makes every backup copy of the firm unreadable, wherever it was copied to.
 * Each chunk is authenticated and the last one is marked final, so a truncated or altered file is refused, not
 * silently restored in part.
 *
 * Format: "LFBK1" | 24-byte stream header | (4-byte big-endian length | sealed chunk)*
 */
class BackupCipher
{
    private const MAGIC = 'LFBK1';

    private const CHUNK = 1048576;

    public static function encryptFile(string $source, string $target, string $key): void
    {
        [$in, $out] = [self::open($source, 'rb'), self::open($target, 'wb')];
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
            fwrite($out, self::MAGIC.$header);
            $chunk = (string) fread($in, self::CHUNK);
            while (true) {
                $next = feof($in) ? '' : (string) fread($in, self::CHUNK);
                $final = $next === '' && feof($in);
                $sealed = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '',
                    $final ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE);
                fwrite($out, pack('N', strlen($sealed)).$sealed);
                if ($final) {
                    break;
                }
                $chunk = $next;
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }

    public static function decryptFile(string $source, string $target, string $key): void
    {
        [$in, $out] = [self::open($source, 'rb'), self::open($target, 'wb')];
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Not an encrypted firm backup.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull((string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES), $key);
            $finished = false;
            while (! $finished && ($len = fread($in, 4)) !== '' && $len !== false) {
                $sealed = (string) fread($in, unpack('N', $len)[1]);
                $opened = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $sealed);
                if ($opened === false) {
                    throw new RuntimeException('The backup does not decrypt with this firm\'s key (wrong key, or the file was altered).');
                }
                [$plain, $tag] = $opened;
                fwrite($out, $plain);
                $finished = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! $finished) {
                throw new RuntimeException('The backup ends early (truncated): refusing a partial restore.');
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($target);
            throw $e;
        } finally {
            fclose($in);
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /** @return resource */
    private static function open(string $path, string $mode)
    {
        return fopen($path, $mode) ?: throw new RuntimeException("Cannot open {$path}.");
    }
}
