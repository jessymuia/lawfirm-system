<?php

namespace App\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * SEC-12: a firm user's TOTP second factor (tenant database; per tenant user, so a person in two firms enrols in each).
 * The secret is encrypted with this deployment's key. See MfaCore for the mechanics.
 */
class Mfa
{
    private function core(): MfaCore
    {
        return new MfaCore(null, 'user_mfa', 'mfa_challenges', 'user_id');
    }

    public static function enabled(User|int $user): bool
    {
        return (new self)->core()->enabled($user instanceof User ? $user->id : $user);
    }

    public static function required(): bool
    {
        return (bool) DB::table('firm_security_settings')->value('require_mfa');
    }

    /** @return array{secret:string,uri:string} */
    public function begin(User $user): array
    {
        return $this->core()->begin($user->id, $user->email, (string) config('app.name', 'Law Firm'));
    }

    /** @return list<string> */
    public function confirm(User $user, string $code): array
    {
        return $this->core()->confirm($user->id, $code);
    }

    public function check(User $user, string $input): bool
    {
        return $this->core()->verify($user->id, $input);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(User $user): array
    {
        return $this->core()->regenerateRecoveryCodes($user->id);
    }

    public function remaining(User $user): int
    {
        return $this->core()->remaining($user->id);
    }

    public function disable(User|int $user): void
    {
        $this->core()->disable($user instanceof User ? $user->id : $user);
    }

    public function challenge(User $user): string
    {
        return $this->core()->challenge($user->id);
    }

    public function redeem(string $token, string $input): ?User
    {
        $id = $this->core()->redeem($token, $input);

        return $id ? User::find($id) : null;
    }
}
