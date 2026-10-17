<?php

namespace App\Security;

use App\Tenancy\Keys\FirmCrypt;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * SEC-12: the one implementation of "a subject's TOTP second factor", used for firm users (tenant database) and
 * for platform staff (control database); only the table names and connection differ.
 *   begin()    stores a secret that protects nothing until confirm() proves the device works
 *   confirm()  turns it on and returns the recovery codes, shown once
 *   challenge() after a correct password: a 5-minute single-use token instead of a session/token
 *   redeem()   the second step: TOTP (replay-protected) or a recovery code; 5 tries then the token dies
 */
class MfaCore
{
    public const CHALLENGE_MINUTES = 5;

    public const MAX_ATTEMPTS = 5;

    /** Wrong second-factor answers per person before further attempts are refused for LOCK_SECONDS. */
    public const MAX_FAILURES = 10;

    public const LOCK_SECONDS = 900;

    public function __construct(private ?string $connection, private string $table, private string $challenges, private string $subject) {}

    /** TEN-13: a firm's people's secrets are under the firm's key; platform staff's stay under the app key. */
    private function open(string $sealed): string
    {
        return $this->connection === null ? FirmCrypt::decryptString($sealed) : Crypt::decryptString($sealed);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }

    public function enabled(int $id): bool
    {
        return $this->db()->table($this->table)->where($this->subject, $id)->whereNotNull('confirmed_at')->exists();
    }

    /** @return array{secret:string,uri:string} */
    public function begin(int $id, string $account, string $issuer): array
    {
        if ($this->enabled($id)) {
            throw new \RuntimeException('Two-factor authentication is already on. Turn it off first to enrol a new device.');
        }
        $secret = Totp::generateSecret();
        $this->db()->table($this->table)->updateOrInsert([$this->subject => $id], [
            'secret' => $this->connection === null ? FirmCrypt::encryptString($secret) : Crypt::encryptString($secret), 'confirmed_at' => null, 'last_used_step' => null, 'recovery_codes' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $account, $issuer)];
    }

    /** @return list<string> recovery codes (plain, once) */
    public function confirm(int $id, string $code): array
    {
        $row = $this->db()->table($this->table)->where($this->subject, $id)->whereNull('confirmed_at')->first();
        if (! $row) {
            throw new \RuntimeException('Start setup first.');
        }
        $step = Totp::verify($this->open($row->secret), $code);
        if ($step === null) {
            throw new \InvalidArgumentException('That code is not right. Check the time on your phone and try the next code.');
        }
        [$plain, $hashes] = RecoveryCodes::generate();
        $this->db()->table($this->table)->where($this->subject, $id)->update(['confirmed_at' => now(), 'last_used_step' => $step, 'recovery_codes' => json_encode($hashes), 'updated_at' => now()]);

        return $plain;
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(int $id): array
    {
        [$plain, $hashes] = RecoveryCodes::generate();
        $this->db()->table($this->table)->where($this->subject, $id)->update(['recovery_codes' => json_encode($hashes), 'updated_at' => now()]);

        return $plain;
    }

    public function remaining(int $id): int
    {
        return count((array) json_decode((string) $this->db()->table($this->table)->where($this->subject, $id)->value('recovery_codes'), true));
    }

    public function disable(int $id): void
    {
        $this->db()->table($this->table)->where($this->subject, $id)->delete();
        $this->db()->table($this->challenges)->where($this->subject, $id)->delete();
    }

    /** @return string the challenge token (shown once; stored hashed) */
    public function challenge(int $id): string
    {
        $this->db()->table($this->challenges)->where('expires_at', '<', now())->delete();
        $token = Str::random(48);
        $this->db()->table($this->challenges)->insert(['token_hash' => hash('sha256', $token), $this->subject => $id, 'expires_at' => now()->addMinutes(self::CHALLENGE_MINUTES), 'created_at' => now()]);

        return $token;
    }

    /** Who a live challenge was issued to (so a failed attempt can be charged to them), without consuming it. */
    public function challengeSubject(string $token): ?int
    {
        $row = $this->db()->table($this->challenges)->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();

        return $row ? (int) $row->{$this->subject} : null;
    }

    /** @return int|null the subject id when the second factor is right; null for any failure (one answer) */
    public function redeem(string $token, string $input): ?int
    {
        $hash = hash('sha256', $token);
        $row = $this->db()->table($this->challenges)->where('token_hash', $hash)->where('expires_at', '>', now())->first();
        if (! $row) {
            return null;
        }
        if ($row->attempts >= self::MAX_ATTEMPTS) {
            $this->db()->table($this->challenges)->where('id', $row->id)->delete();

            return null;
        }
        $this->db()->table($this->challenges)->where('id', $row->id)->increment('attempts');

        // Per person, not per challenge: asking for a fresh challenge (needs the password) must not reset the guessing budget.
        $key = 'mfa-fail:'.$this->table.':'.$row->{$this->subject};
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return null;
        }
        $id = (int) $row->{$this->subject};
        if (! $this->verify($id, $input)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, self::LOCK_SECONDS);

            return null;
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $this->db()->table($this->challenges)->where('id', $row->id)->delete();   // single use

        return $id;
    }

    /** Check a TOTP code or recovery code for a subject who has MFA on. */
    public function verify(int $id, string $input): bool
    {
        $row = $this->db()->table($this->table)->where($this->subject, $id)->whereNotNull('confirmed_at')->first();
        if (! $row) {
            return false;
        }

        if (RecoveryCodes::looksLike($input)) {
            $left = RecoveryCodes::consume((array) json_decode((string) $row->recovery_codes, true), $input);
            if ($left === null) {
                return false;
            }
            // compare-and-set on the stored list, so one recovery code cannot be spent twice by parallel requests
            return $this->db()->table($this->table)->where($this->subject, $id)->where('recovery_codes', $row->recovery_codes)
                ->update(['recovery_codes' => json_encode($left), 'updated_at' => now()]) === 1;
        }

        $step = Totp::verify($this->open($row->secret), $input, $row->last_used_step === null ? null : (int) $row->last_used_step);
        if ($step === null) {
            return false;
        }

        // compare-and-set: two simultaneous submissions of one code cannot both win
        return $this->db()->table($this->table)->where($this->subject, $id)
            ->where(fn ($q) => $q->whereNull('last_used_step')->orWhere('last_used_step', '<', $step))
            ->update(['last_used_step' => $step, 'updated_at' => now()]) === 1;
    }
}
