<?php

namespace App\Signup;

use App\Jobs\ProvisionSignup;
use App\Mail\SignupVerification;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * PLAT-13: self-service trial signup. submit() records the request and emails a link; nothing is created until the link
 * is opened (verify()), which queues the provisioning (ProvisionSignup): the firm's database, catalog, first
 * administrator (the person who signed up, with the password they chose) and a free trial (billing.trial.days).
 *
 * Only the token's SHA-256 and the password's bcrypt hash are stored, and the hash is cleared once the firm exists.
 */
class SignupService
{
    public const LINK_HOURS = 24;

    private function db()
    {
        return DB::connection('control');
    }

    /** Signup needs the multi-tenant mode, its own host and a base domain to give the firm its address. */
    public static function available(): bool
    {
        return app(TenantManager::class)->isMulti() && filled(config('tenancy.signup_host')) && filled(config('tenancy.base_domain'));
    }

    /** null when the address can be taken, else why not. */
    public function slugProblem(string $slug, ?string $email = null): ?string
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/', $slug) || str_contains($slug, '--')) {
            return 'Use 3 to 32 lowercase letters, digits and single hyphens, starting and ending with a letter or digit.';
        }
        if (in_array($slug, (array) config('tenancy.reserved_slugs'), true)) {
            return 'That address is reserved. Please choose another.';
        }
        if (Tenant::where('slug', $slug)->exists()) {
            return 'That address is taken. Please choose another.';
        }
        // held by someone else's unexpired, unverified signup (the same person may submit again)
        $held = $this->db()->table('signups')->where('slug', $slug)->whereIn('status', ['pending', 'provisioning'])
            ->where('expires_at', '>', now())
            ->when($email, fn ($q) => $q->where('email', '!=', Str::lower(trim($email))))
            ->exists();

        return $held ? 'That address is taken. Please choose another.' : null;
    }

    /**
     * @param  array{firm_name:string,slug:string,name:string,email:string,password:string,phone?:?string,region?:?string,currency?:?string}  $d
     */
    public function submit(array $d, ?string $ip = null): void
    {
        $email = Str::lower(trim($d['email']));
        $slug = Str::lower(trim($d['slug']));
        if ($problem = $this->slugProblem($slug, $email)) {
            throw new SignupException($problem, 'slug');
        }

        $token = Str::random(48);
        $this->db()->transaction(function () use ($d, $email, $slug, $token, $ip) {
            // one live request per person: a new submission replaces their earlier unverified one
            $this->db()->table('signups')->where('email', $email)->where('status', 'pending')->delete();
            $this->db()->table('signups')->insert([
                'token_hash' => hash('sha256', $token), 'firm_name' => trim($d['firm_name']), 'slug' => $slug,
                'name' => trim($d['name']), 'email' => $email, 'password' => Hash::make($d['password']),
                'phone' => $d['phone'] ?? null, 'region' => $d['region'] ?? null,
                'currency' => $d['currency'] ?? (string) config('billing.trial.currency', 'KES'),
                'status' => 'pending', 'ip' => $ip, 'expires_at' => now()->addHours(self::LINK_HOURS),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $link = 'https://'.config('tenancy.signup_host').'/signup/verify?token='.$token;
        Mail::to($email)->send(new SignupVerification(trim($d['firm_name']), $link, self::LINK_HOURS));
    }

    /** Opening the link: queue the provisioning (again, if an earlier attempt failed). Idempotent. */
    public function verify(string $token): object
    {
        $row = $this->find($token);
        if ($row->status === 'pending' && now()->gte($row->expires_at)) {
            throw new SignupException('This link has expired. Please sign up again.', 'token', 410);
        }
        if (in_array($row->status, ['pending', 'failed'], true)) {
            // the address may have been taken since the request (by a faster signup, or by the platform team)
            if ($row->status === 'pending' && ($problem = $this->slugProblem($row->slug, $row->email))) {
                $this->db()->table('signups')->where('id', $row->id)->update(['status' => 'failed', 'error' => $problem, 'updated_at' => now()]);
                throw new SignupException($problem.' Please sign up again with a different address.', 'slug', 409);
            }
            $this->db()->table('signups')->where('id', $row->id)->whereIn('status', ['pending', 'failed'])
                ->update(['status' => 'provisioning', 'error' => null, 'verified_at' => $row->verified_at ?? now(), 'updated_at' => now()]);
            ProvisionSignup::dispatch((int) $row->id);
        }

        return $this->status($token);
    }

    /** What the waiting page shows. Never reveals anything the token's holder did not submit. */
    public function status(string $token): object
    {
        $row = $this->find($token);

        return (object) [
            'status' => $row->status,
            'firm_name' => $row->firm_name,
            'login_url' => $row->status === 'ready' ? self::loginUrl($row->slug) : null,
            'error' => $row->status === 'failed' ? 'We could not finish setting up your firm. Please try again in a few minutes; if it keeps failing, contact support.' : null,
        ];
    }

    public static function loginUrl(string $slug): string
    {
        return 'https://'.$slug.'.'.config('tenancy.base_domain').'/login';
    }

    private function find(string $token): object
    {
        return $this->db()->table('signups')->where('token_hash', hash('sha256', $token))->first()
            ?? throw new SignupException('This link is not valid. Please sign up again.', 'token', 404);
    }
}
