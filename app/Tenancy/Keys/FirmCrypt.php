<?php

namespace App\Tenancy\Keys;

use App\Tenancy\TenantManager;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;

/**
 * TEN-13: encryption for a firm's own stored data (its secrets). With a firm active that has a data key, values are
 * encrypted with that key; otherwise (single-firm installs, firms without a key) with the app key, as before. Reading
 * accepts both, so values written before the firm had a key keep working until `secrets:rotate` moves them over.
 *
 * Not for transport (cookies, signed state in a URL): those stay on the app key via Crypt.
 */
class FirmCrypt
{
    public static function encrypter(): Encrypter
    {
        $tenant = app(TenantManager::class)->current();

        return ($tenant ? app(FirmKeys::class)->encrypter($tenant) : null) ?? Crypt::getFacadeRoot();
    }

    public static function encryptString(string $value): string
    {
        return self::encrypter()->encryptString($value);
    }

    public static function decryptString(string $payload): string
    {
        return self::encrypter()->decryptString($payload);
    }
}
