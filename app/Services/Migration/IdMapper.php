<?php

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;

/**
 * MIG-4/§6.3 Phase 2. A thin wrapper around the `migration_id_maps`
 * table — every entity imported records its old_id -> new_id mapping
 * here immediately after insert, and every subsequent entity that
 * references it (an FK column, or audits.auditable_id/user_id) looks
 * the new id up through here rather than assuming ids are preserved.
 *
 * In-process cache: within a single `migration:import` run this avoids
 * a DB round-trip per lookup (some entities, e.g. users, get looked up
 * once per row of many later tables) while still persisting to the
 * `migration_id_maps` table so a resumed/re-run import (or the
 * audits-rewrite step, which runs after every other entity) can look
 * mappings up without needing the whole import to have happened in one
 * uninterrupted process.
 */
class IdMapper
{
    /** @var array<string, array<int, int>> */
    private array $cache = [];

    public function record(string $entityType, int $oldId, int $newId): void
    {
        DB::table('migration_id_maps')->updateOrInsert(
            ['entity_type' => $entityType, 'old_id' => $oldId],
            ['new_id' => $newId, 'updated_at' => now(), 'created_at' => now()]
        );

        $this->cache[$entityType][$oldId] = $newId;
    }

    public function getNewId(string $entityType, ?int $oldId): ?int
    {
        if ($oldId === null) {
            return null;
        }

        if (isset($this->cache[$entityType][$oldId])) {
            return $this->cache[$entityType][$oldId];
        }

        $newId = DB::table('migration_id_maps')
            ->where('entity_type', $entityType)
            ->where('old_id', $oldId)
            ->value('new_id');

        if ($newId !== null) {
            $this->cache[$entityType][$oldId] = $newId;
        }

        return $newId;
    }

    /**
     * Used by the audits-rewrite step: audits.auditable_type is a fully
     * qualified class name (e.g. 'App\Models\Cases'), but entity_type in
     * this table is the plain entity key (e.g. 'cases') used everywhere
     * else in the import — this is the one place that translation needs
     * to happen.
     */
    public static function entityTypeForAuditableClass(string $auditableClass): ?string
    {
        return [
            'App\\Models\\Client' => 'clients',
            'App\\Models\\Cases' => 'cases',
            'App\\Models\\Hearing' => 'hearings',
            'App\\Models\\Task' => 'tasks',
            'App\\Models\\Invoice' => 'invoices',
            'App\\Models\\InvoiceItem' => 'invoice_items',
            'App\\Models\\Expense' => 'expenses',
            'App\\Models\\Document' => 'documents',
            'App\\Models\\Court' => 'courts',
            'App\\Models\\CourtType' => 'court_types',
            'App\\Models\\User' => 'users',
        ][$auditableClass] ?? null;
    }
}
