<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/**
 * PLAT-2: offline verification of a signed license file. No network, no phone-home.
 *
 * File (JSON):  {"payload": "<the exact JSON string that was signed>", "signature": "<base64 Ed25519>"}
 * Payload:      license_id, licensee, plan, issued_at, expires_at, grace_days?, features?, limits?
 *
 * The signed bytes are the payload STRING itself, so there is no canonicalisation
 * to get wrong: any change to it (a plan upgrade, a later expiry) invalidates the signature.
 */
class LicenseVerifier
{
    /** @param list<string> $publicKeys base64 Ed25519 public keys; any one may have signed (key rotation) */
    public function __construct(private array $publicKeys, private int $defaultGraceDays = 30)
    {
    }

    public function verify(string $fileContents, CarbonImmutable $now): LicenseStatus
    {
        $file = json_decode($fileContents, true);
        if (! is_array($file) || ! is_string($file['payload'] ?? null) || ! is_string($file['signature'] ?? null)) {
            return new LicenseStatus(LicenseStatus::INVALID, reason: 'unreadable license file');
        }

        $signature = base64_decode($file['signature'], true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES || ! $this->signedByKnownKey($file['payload'], $signature)) {
            return new LicenseStatus(LicenseStatus::INVALID, reason: 'signature does not match');
        }

        $payload = json_decode($file['payload'], true);
        $issued = $this->date($payload['issued_at'] ?? null);
        $expires = $this->date($payload['expires_at'] ?? null);
        if (! is_array($payload) || ! $issued || ! $expires || ! is_string($payload['plan'] ?? null)) {
            return new LicenseStatus(LicenseStatus::INVALID, reason: 'license is missing required fields');
        }

        $grace = max(0, (int) ($payload['grace_days'] ?? $this->defaultGraceDays));
        $graceEnds = $expires->addDays($grace);

        // A day of tolerance for clock differences between the issuer and this server.
        if ($issued->subDay()->greaterThan($now)) {
            return new LicenseStatus(LicenseStatus::NOT_YET_VALID, $payload, $expires, $graceEnds, 'issued '.$issued->toDateString());
        }
        if ($now->lessThanOrEqualTo($expires)) {
            return new LicenseStatus(LicenseStatus::VALID, $payload, $expires, $graceEnds);
        }
        if ($now->lessThanOrEqualTo($graceEnds)) {
            return new LicenseStatus(LicenseStatus::GRACE, $payload, $expires, $graceEnds);
        }

        return new LicenseStatus(LicenseStatus::EXPIRED, $payload, $expires, $graceEnds);
    }

    private function signedByKnownKey(string $message, string $signature): bool
    {
        foreach ($this->publicKeys as $b64) {
            $key = base64_decode($b64, true);
            if ($key !== false && strlen($key) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
                && sodium_crypto_sign_verify_detached($signature, $message, $key)) {
                return true;
            }
        }

        return false;
    }

    private function date(mixed $v): ?CarbonImmutable
    {
        try {
            return is_string($v) ? CarbonImmutable::parse($v) : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
