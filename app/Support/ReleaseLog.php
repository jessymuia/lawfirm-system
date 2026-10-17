<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLAT-6: append-only record of which code release last upgraded this installation, so a
 * downgrade (older code against a newer schema) can be detected and refused.
 * Lives on the control DB in multi-tenant mode (one row per deployment, not per tenant) and on
 * the default DB for single-tenant installs. The table is created on demand: it is
 * deployment bookkeeping, not application data, and must exist before any migration state is known.
 */
class ReleaseLog
{
    public const TABLE = 'app_releases';

    public static function connection(): string
    {
        return config('tenancy.mode') === 'multi' ? 'control' : config('database.default');
    }

    private static function ensure(): void
    {
        $schema = Schema::connection(self::connection());
        if (! $schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($t) {
                $t->id();
                $t->string('version', 64);
                $t->string('previous_version', 64)->nullable();
                $t->timestamp('applied_at')->useCurrent();
            });
        }
    }

    public static function current(): ?string
    {
        $schema = Schema::connection(self::connection());
        if (! $schema->hasTable(self::TABLE)) {
            return null;
        }

        return DB::connection(self::connection())->table(self::TABLE)->orderByDesc('id')->value('version');
    }

    /** Record a release; a no-op when it is already the latest (keeps upgrade idempotent). */
    public static function record(string $version): bool
    {
        self::ensure();
        $previous = self::current();
        if ($previous === $version) {
            return false;
        }
        DB::connection(self::connection())->table(self::TABLE)->insert([
            'version' => $version, 'previous_version' => $previous, 'applied_at' => now(),
        ]);

        return true;
    }

    /**
     * Is running `$code` against a schema last upgraded by `$recorded` safe? Expand/contract (docs/MIGRATION_POLICY.md)
     * guarantees the PREVIOUS minor release works on the newer schema, so that window is a supported rollback.
     *
     * @return 'none'|'warn'|'block'
     */
    public static function downgradeRisk(string $recorded, string $code): string
    {
        if (self::compare($recorded, $code) <= 0) {
            return 'none';
        }
        [$rM, $rm] = array_map('intval', array_pad(explode('.', preg_replace('/^v|-.*$/', '', $recorded)), 2, 0));
        [$cM, $cm] = array_map('intval', array_pad(explode('.', preg_replace('/^v|-.*$/', '', $code)), 2, 0));
        if ($rM !== $cM || ($rm - $cm) > 1) {
            return 'block';
        }

        return 'warn';
    }

    /** semver-ish comparison that understands -rc.N and 'dev' (treated as unknown, never blocks). */
    public static function compare(string $a, string $b): int
    {
        if ($a === 'dev' || $b === 'dev') {
            return 0;
        }
        $norm = fn (string $v) => preg_replace('/^v/', '', str_replace('-rc.', '-RC', $v));

        return version_compare($norm($a), $norm($b));
    }
}
