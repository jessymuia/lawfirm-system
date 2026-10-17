<?php

namespace App\Portal;

use App\Conflicts\Names;
use App\Jobs\SendSmsJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FUN-10: how a client signs in to the portal. Clients are not users of the firm and have no password: they ask for a
 * one-time code, which is texted to the phone on their client record, and exchange it for a portal session.
 *
 *  - Only clients the firm has given portal access (portal_accounts.enabled) can sign in.
 *  - Asking for a code answers the same whether or not the number belongs to anyone, so the portal cannot be used to
 *    find out who the firm's clients are.
 *  - Codes are six digits, live ten minutes, allow five tries and are single use; only an HMAC of them is stored.
 *  - Sessions are bearer tokens (only the SHA-256 is stored), ending after an hour without use or twelve hours at most.
 */
class PortalAuth
{
    public const CODE_MINUTES = 10;

    public const CODE_TRIES = 5;

    public const IDLE_MINUTES = 60;

    public const MAX_HOURS = 12;

    /** Text a sign-in code to the client with this phone number, if they may use the portal. Says nothing either way. */
    public function requestCode(string $phone): void
    {
        $clientId = $this->clientForPhone($phone);
        if ($clientId === null) {
            return;
        }
        // one live code at a time: a new request replaces the previous one
        DB::table('portal_login_codes')->where('client_id', $clientId)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
        $code = (string) random_int(100000, 999999);
        DB::table('portal_login_codes')->insert([
            'client_id' => $clientId, 'code_hash' => $this->hash($code), 'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $to = (string) DB::table('clients')->where('id', $clientId)->value('phone_number');
        SendSmsJob::dispatch($to, "Your sign-in code for the ".config('app.name')." client portal is {$code}. It expires in ".self::CODE_MINUTES.' minutes. Do not share it.');
    }

    /** @return array{token: string, expires_at: string}|null a new session, or null when the code is wrong, used up or expired */
    public function verify(string $phone, string $code, ?string $ip): ?array
    {
        $clientId = $this->clientForPhone($phone);
        if ($clientId === null) {
            return null;
        }

        return DB::transaction(function () use ($clientId, $code, $ip) {
            $row = DB::table('portal_login_codes')->where('client_id', $clientId)->whereNull('used_at')
                ->where('expires_at', '>', now())->orderByDesc('id')->lockForUpdate()->first();
            if (! $row || $row->attempts >= self::CODE_TRIES) {
                return null;
            }
            if (! hash_equals($row->code_hash, $this->hash($code))) {
                DB::table('portal_login_codes')->where('id', $row->id)->increment('attempts');

                return null;
            }
            DB::table('portal_login_codes')->where('id', $row->id)->update(['used_at' => now(), 'updated_at' => now()]);

            $token = Str::random(64);
            $expires = now()->addHours(self::MAX_HOURS);
            DB::table('portal_sessions')->insert([
                'client_id' => $clientId, 'token_hash' => hash('sha256', $token), 'expires_at' => $expires, 'last_seen_at' => now(),
                'ip_address' => $ip, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('portal_accounts')->where('client_id', $clientId)->update(['last_login_at' => now(), 'updated_at' => now()]);

            return ['token' => $token, 'expires_at' => $expires->toIso8601String()];
        });
    }

    /** The client a session token belongs to, refreshing its idle timer; null when it is not (or no longer) valid. */
    public function clientFor(string $token): ?int
    {
        $s = DB::table('portal_sessions')->where('token_hash', hash('sha256', $token))->first();
        if (! $s || $s->revoked_at || now()->gte($s->expires_at) || now()->subMinutes(self::IDLE_MINUTES)->gt($s->last_seen_at)) {
            return null;
        }
        // access can be withdrawn while a session is open
        if (! DB::table('portal_accounts')->where('client_id', $s->client_id)->where('enabled', true)->exists()) {
            return null;
        }
        if (now()->subMinute()->gt($s->last_seen_at)) {
            DB::table('portal_sessions')->where('id', $s->id)->update(['last_seen_at' => now()]);
        }

        return (int) $s->client_id;
    }

    public function logout(string $token): void
    {
        DB::table('portal_sessions')->where('token_hash', hash('sha256', $token))->update(['revoked_at' => now(), 'updated_at' => now()]);
    }

    /** End every portal session of a client (access withdrawn). */
    public function revokeAll(int $clientId): void
    {
        DB::table('portal_sessions')->where('client_id', $clientId)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('portal_login_codes')->where('client_id', $clientId)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
    }

    /** The portal-enabled, live client whose phone number is this one (compared by its last nine digits). */
    private function clientForPhone(string $phone): ?int
    {
        $key = Names::phone($phone);
        if ($key === '') {
            return null;
        }
        $candidates = DB::table('clients')
            ->join('portal_accounts', 'portal_accounts.client_id', '=', 'clients.id')
            ->where('portal_accounts.enabled', true)->whereNull('clients.deleted_at')
            ->where('clients.phone_number', 'like', '%'.substr($key, -3))
            ->get(['clients.id', 'clients.phone_number']);
        $match = $candidates->filter(fn ($c) => Names::phone($c->phone_number) === $key);

        // two clients on one number cannot be told apart by a code sent to it
        return $match->count() === 1 ? (int) $match->first()->id : null;
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
