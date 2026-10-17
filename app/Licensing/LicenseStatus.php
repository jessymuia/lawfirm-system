<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/** PLAT-2: the outcome of checking the installed license, at one moment. */
final class LicenseStatus
{
    public const VALID = 'valid';            // within its term

    public const GRACE = 'grace';            // past expiry, inside grace: everything still works, with a warning

    public const EXPIRED = 'expired';        // past grace: read-only

    public const MISSING = 'missing';        // none installed: read-only (when a license is required)

    public const INVALID = 'invalid';        // unreadable, bad signature, unknown key, tampered: read-only

    public const NOT_YET_VALID = 'not_yet_valid';

    public function __construct(
        public readonly string $state,
        public readonly ?array $payload = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?CarbonImmutable $graceEndsAt = null,
        public readonly ?string $reason = null,
    ) {}

    /** Writes allowed? Only while the license is in term or in grace. */
    public function allowsWrites(): bool
    {
        return in_array($this->state, [self::VALID, self::GRACE], true);
    }

    public function notice(): ?string
    {
        return match ($this->state) {
            self::GRACE => 'Your license expired on '.$this->expiresAt?->toDateString().'. Everything keeps working until '.$this->graceEndsAt?->toDateString().'; renew before then.',
            self::EXPIRED => 'Your license expired on '.$this->expiresAt?->toDateString().'. The system is read-only: your data is safe and can be viewed and exported. Install a renewed license to continue editing.',
            self::MISSING => 'No license is installed. The system is read-only until one is installed.',
            self::INVALID => 'The installed license could not be verified ('.$this->reason.'). The system is read-only until a valid license is installed.',
            self::NOT_YET_VALID => 'The installed license is not valid yet ('.$this->reason.'). The system is read-only until then.',
            default => null,
        };
    }

    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'licensee' => $this->payload['licensee'] ?? null,
            'license_id' => $this->payload['license_id'] ?? null,
            'plan' => $this->payload['plan'] ?? null,
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'grace_ends_at' => $this->graceEndsAt?->toIso8601String(),
            'read_only' => ! $this->allowsWrites(),
            'notice' => $this->notice(),
        ];
    }
}
