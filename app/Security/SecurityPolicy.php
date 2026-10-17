<?php

namespace App\Security;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/** SEC-12: the firm's own security policy (one row). Changes are written to the firm's audit log. */
final class SecurityPolicy
{
    public static function get(): array
    {
        return ['require_mfa' => Mfa::required()];
    }

    public static function setRequireMfa(bool $value): array
    {
        $before = self::get();
        if ($value && ! Mfa::enabled(Auth::id() ?? 0)) {
            throw new \RuntimeException('Turn on two-factor authentication for your own account first, so enforcing it cannot lock you out.');
        }
        $row = DB::table('firm_security_settings')->first();
        if ($row) {
            DB::table('firm_security_settings')->where('id', $row->id)->update(['require_mfa' => $value, 'updated_by' => Auth::id(), 'updated_at' => now()]);
        } else {
            DB::table('firm_security_settings')->insert(['require_mfa' => $value, 'updated_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $after = self::get();
        self::audit('security_policy_updated', 'security_policy', 0, $before, $after);

        return $after;
    }

    public static function audit(string $event, string $type, int $id, array $old, array $new): void
    {
        $actor = Auth::user();
        DB::table('audits')->insert([
            'user_type' => $actor ? get_class($actor) : null, 'user_id' => Auth::id(),
            'event' => $event, 'auditable_type' => $type, 'auditable_id' => $id,
            'old_values' => json_encode($old), 'new_values' => json_encode($new),
            'url' => request()->fullUrl(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            'tags' => 'security', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
