<?php

namespace App\Portability;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** PLAT-9: which tables travel, and the rows that must never travel (support principals). */
final class TableCatalog
{
    public const SUPPORT_ROLES = ['support-read', 'support-full'];

    /** @return list<string> every table in the active database */
    public static function existing(): array
    {
        return array_values(array_map(fn ($t) => $t['name'], Schema::getTables()));
    }

    /** @return list<string> tables with no decision in config/portability.php */
    public static function unclassified(): array
    {
        $known = array_merge((array) config('portability.include'), array_keys((array) config('portability.exclude')));

        return array_values(array_diff(self::existing(), $known));
    }

    /** @return list<string> included tables that exist here, in config order */
    public static function included(): array
    {
        $existing = self::existing();

        return array_values(array_filter((array) config('portability.include'), fn ($t) => in_array($t, $existing, true)));
    }

    /** @return list<string> */
    public static function bootstrap(): array
    {
        return (array) config('portability.bootstrap');
    }

    /** @return list<int> ids of platform-staff support principals (never part of a firm's portable data) */
    public static function principalIds(): array
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'platform_staff_id')) {
            return [];
        }

        return DB::table('users')->whereNotNull('platform_staff_id')->pluck('id')->map(fn ($i) => (int) $i)->all();
    }

    /** @return list<int> */
    public static function supportRoleIds(): array
    {
        return Schema::hasTable('roles') ? DB::table('roles')->whereIn('name', self::SUPPORT_ROLES)->pluck('id')->map(fn ($i) => (int) $i)->all() : [];
    }

    /** The query for exactly the rows of $table that belong in a bundle. */
    public static function scope(string $table): Builder
    {
        $q = DB::table($table);
        $principals = self::principalIds();
        $roles = self::supportRoleIds();

        switch ($table) {
            case 'users':
                $principals && $q->whereNotIn('id', $principals);
                break;
            case 'roles':
                $roles && $q->whereNotIn('id', $roles);
                break;
            case 'model_has_roles':
            case 'model_has_permissions':
                $principals && $q->whereNotIn('model_id', $principals);
                if ($table === 'model_has_roles' && $roles) {
                    $q->whereNotIn('role_id', $roles);
                }
                break;
            case 'role_has_permissions':
                $roles && $q->whereNotIn('role_id', $roles);
                break;
        }

        return $q;
    }

    /** Rows in a stable order, streamed in chunks. @return \Generator<int,array<string,mixed>> */
    public static function rows(string $table): \Generator
    {
        $q = self::scope($table);
        $omit = array_flip((array) config("portability.omit_columns.{$table}", []));
        if (Schema::hasColumn($table, 'id')) {
            foreach ($q->orderBy('id')->lazyById(1000, 'id') as $row) {
                yield array_diff_key((array) $row, $omit);
            }

            return;
        }
        foreach ($q->get() as $row) {   // composite-key pivots: small
            yield array_diff_key((array) $row, $omit);
        }
    }

    /** @return list<string> the columns that travel for $table */
    public static function columns(string $table): array
    {
        return array_values(array_diff(Schema::getColumnListing($table), (array) config("portability.omit_columns.{$table}", [])));
    }

    public static function connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
