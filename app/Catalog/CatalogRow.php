<?php

namespace App\Catalog;

/**
 * TEN-11: model guard for tables that hold system catalog rows next to firm rows.
 *
 * Enforced on the model, not in controllers, so every entry point (API, console,
 * jobs, imports) gets it. Outside SystemCatalog::sync() a system row may only
 * have its alias changed, and can never be deleted; nothing may make a row a
 * system row. Query-builder bulk writes bypass model events: do not use them on
 * these tables.
 *
 * @property string|null $catalog_key
 * @property bool $is_system
 * @property string|null $alias
 */
trait CatalogRow
{
    /** Columns a firm may change on a system row. */
    private static array $overlayColumns = ['alias', 'updated_by', 'updated_at'];

    public static function bootCatalogRow(): void
    {
        static::creating(function (self $row) {
            if (! SystemCatalog::isSyncing() && ($row->is_system || $row->catalog_key !== null)) {
                throw new SystemCatalogException('System reference data is maintained by the platform and cannot be created here.');
            }
        });

        static::updating(function (self $row) {
            if (SystemCatalog::isSyncing()) {
                return;
            }
            $wasSystem = (bool) $row->getOriginal('is_system');
            $changed = array_diff(array_keys($row->getDirty()), self::$overlayColumns);
            if ($wasSystem && $changed !== []) {
                throw new SystemCatalogException('"'.$row->getOriginal('name').'" is system reference data and cannot be changed. You can set a local name (alias) for it instead.');
            }
            if (! $wasSystem && ($row->isDirty('is_system') || $row->isDirty('catalog_key'))) {
                throw new SystemCatalogException('A firm record cannot be turned into system reference data.');
            }
        });

        static::deleting(function (self $row) {
            if (! SystemCatalog::isSyncing() && $row->getOriginal('is_system')) {
                throw new SystemCatalogException('"'.$row->getOriginal('name').'" is system reference data and cannot be deleted.');
            }
        });
    }

    public function initializeCatalogRow(): void
    {
        $this->mergeCasts(['is_system' => 'boolean']);
    }

    /** What firms see: their alias if they set one, otherwise the official name. */
    public function displayName(): string
    {
        return $this->alias !== null && $this->alias !== '' ? $this->alias : (string) $this->name;
    }

    /** Catalog fields every API row of these tables carries. */
    public function catalogFields(): array
    {
        return [
            'name' => $this->displayName(),
            'official_name' => $this->name,
            'alias' => $this->alias,
            'is_system' => (bool) $this->is_system,
            'retired' => (bool) $this->is_system && (int) $this->getAttribute('archive') === 1,
        ];
    }
}
