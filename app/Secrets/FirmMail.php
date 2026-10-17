<?php

namespace App\Secrets;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The active firm's own outgoing mail. Called whenever a firm becomes the active one (a request, or a queued job on a
 * long-lived worker), so mail goes out through that firm's mailbox, and a firm without one gets the platform's default,
 * never the previous firm's.
 */
class FirmMail
{
    /** @var array{default: mixed, from: mixed}|null the deployment's mail settings, captured before any firm changed them */
    private static ?array $base = null;

    public static function apply(): void
    {
        self::$base ??= ['default' => config('mail.default'), 'from' => config('mail.from')];
        config(['mail.default' => self::$base['default'], 'mail.from' => self::$base['from']]);

        try {
            $c = app(IntegrationCredentials::class);
            $host = DB::table('integration_credentials')->where('provider', 'mail')->exists() ? $c->resolve('mail', 'host') : null;
            $from = $c->resolve('mail', 'from_address');
            $name = $c->resolve('mail', 'from_name');
            if ($host) {
                config(['mail.mailers.firm' => array_filter([
                    'transport' => 'smtp', 'host' => $host, 'port' => (int) ($c->resolve('mail', 'port') ?: 587),
                    'username' => $c->resolve('mail', 'username'), 'password' => $c->resolve('mail', 'password'),
                    'scheme' => $c->resolve('mail', 'scheme') ?: null, 'timeout' => 15,
                ], fn ($v) => $v !== null && $v !== '' ), 'mail.default' => 'firm']);
                Mail::purge('firm');
            }
            if ($from) {
                config(['mail.from' => ['address' => $from, 'name' => $name ?: config('mail.from.name')]]);
            }
        } catch (\Throwable) {
            // the firm's database may not have the table yet (provisioning, migration): the deployment's mail stands
        }
    }

    /** Back to the deployment's mail (no firm active). */
    public static function reset(): void
    {
        if (self::$base) {
            config(['mail.default' => self::$base['default'], 'mail.from' => self::$base['from']]);
        }
    }
}
