<?php

namespace App\Support;

use RuntimeException;

/**
 * SEC-15: read secrets from files instead of the environment ("FOO_FILE=/run/secrets/foo" fills FOO).
 *
 * This is the contract Docker/Swarm secrets, Kubernetes secret volumes and Vault/AWS/GCP agents all speak, so the secret
 * lives in the managed store and is mounted, never written into a `.env` on the box. Rules:
 *  - the path must be absolute and readable, and the file non-empty, otherwise boot fails loudly (never silently without the secret);
 *  - setting both FOO and FOO_FILE is an error (two sources of truth);
 *  - one trailing newline is stripped (editors and `echo` add one); nothing else is changed.
 */
class SecretFiles
{
    /**
     * Only these variables may be filled from a file. An allow-list on purpose: unrelated variables that merely end in
     * _FILE (PIP_CONFIG_FILE, ...) must never be read, and a missing one must never stop the boot. Extend it per
     * deployment with SECRET_FILE_ALLOW=NAME1,NAME2.
     */
    public const ALLOWED = [
        'APP_KEY', 'APP_PREVIOUS_KEYS', 'DB_PASSWORD', 'DB_REPLICA_PASSWORD', 'CONTROL_DB_PASSWORD', 'DB_SOURCE_PASSWORD', 'REDIS_PASSWORD', 'MEILISEARCH_KEY', 'MAIL_PASSWORD',
        'MAILGUN_SECRET', 'POSTMARK_TOKEN', 'AWS_SECRET_ACCESS_KEY', 'TEXTSMS_API_KEY', 'TEXTSMS_PARTNER_ID', 'LICENSE_PUBLIC_KEYS',
        'STRIPE_SECRET_KEY', 'STRIPE_WEBHOOK_SECRET', 'MPESA_CONSUMER_KEY', 'MPESA_CONSUMER_SECRET', 'MPESA_PASSKEY', 'MPESA_CALLBACK_TOKEN',   // PLAT-4
        'ERROR_TRACKING_DSN', 'SENTRY_DSN', 'OTEL_EXPORTER_OTLP_HEADERS',   // ENG-24
    ];

    /** @param array<array-key,mixed> $env (getenv()/$_ENV: values are not guaranteed strings)  @return array<string,string> name => value to inject */
    public static function resolve(array $env): array
    {
        $allowed = array_merge(self::ALLOWED, array_filter(array_map('trim', explode(',', (string) ($env['SECRET_FILE_ALLOW'] ?? '')))));
        $out = [];
        foreach ($env as $name => $path) {
            if (! is_string($name) || ! str_ends_with($name, '_FILE') || strlen($name) <= 5 || ! is_string($path) || $path === '') {
                continue;
            }
            $target = substr($name, 0, -5);
            if (! in_array($target, $allowed, true)) {
                continue;
            }
            if (isset($env[$target]) && $env[$target] !== '') {
                throw new RuntimeException("Both {$target} and {$name} are set; use one.");
            }
            if (! str_starts_with($path, '/')) {
                throw new RuntimeException("{$name} must be an absolute path.");
            }
            $raw = is_file($path) && is_readable($path) ? file_get_contents($path) : false;
            if ($raw === false) {
                throw new RuntimeException("{$name}: cannot read {$path}.");
            }
            $value = preg_replace('/\r?\n\z/', '', $raw, 1);
            if ($value === '') {
                throw new RuntimeException("{$name}: {$path} is empty.");
            }
            $out[$target] = $value;
        }

        return $out;
    }

    /** Resolve from the real process environment and publish into it, before Laravel reads configuration. */
    public static function load(): void
    {
        $env = getenv() ?: [];
        foreach (self::resolve($env) as $name => $value) {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}
