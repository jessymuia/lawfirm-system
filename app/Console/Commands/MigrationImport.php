<?php

namespace App\Console\Commands;

use App\Services\Migration\ForeignKeyRegistry;
use App\Services\Migration\IdMapper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandAlias;

class MigrationImport extends Command
{
    /**
     * §6.3 Phase 2 (Transform & Load). Reads the JSON produced by
     * `migration:export` (Phase 1) and imports every entity in the exact
     * dependency order §6.3 specifies, remapping every foreign key
     * through IdMapper as it goes (see that class's own docblock) —
     * every row is guaranteed to get a *new* auto-increment id here
     * (the destination is not assumed empty), so nothing downstream can
     * assume old ids survived.
     *
     * Two invariants this command exists specifically to protect, both
     * called out explicitly in §6.3:
     *   - "preserve original created_at/updated_at/created_by (never
     *     stamp import-time values)" — every insert here copies those
     *     columns verbatim from the export, never uses now()/auth()->id().
     *   - "Maintain an old_id -> new_id mapping table per entity;
     *     rewrite auditable_id/user_id in audits from it" — see the
     *     dedicated importAudits() step, which runs last for exactly
     *     this reason (every entity audits could reference must already
     *     have a mapping recorded).
     *
     * Spatie's roles/permissions tables are deliberately NOT
     * blind-inserted like every other entity: the destination database
     * is expected to already have its own roles/permissions seeded
     * (see database/seeders/RoleSeeder.php) before any migration runs,
     * so importing those merges by *name* against what already exists
     * rather than assuming the destination starts empty and duplicating
     * "admin"/"advocate"/"clerk" under new ids.
     */
    protected $signature = 'migration:import {--input= : Directory produced by migration:export}';

    protected $description = '[legacy one-off, see portability:import] Phase 2: load the Phase 1 export (§6.3). Not for the legacy cutover: it applies no status mapping (docs/ops/LEGACY_CUTOVER.md)';

    /**
     * Import order and FK remap spec for every "simple" entity — i.e.
     * every table except the Spatie permission tables (merged by name,
     * handled separately) and audits (polymorphic, imported last).
     *
     * @var array<string, array{fk: array<string, string>}>
     */
    private const IMPORT_SPECS = [
        'users' => ['fk' => []],
        'court_types' => ['fk' => []],
        'courts' => ['fk' => ['type' => 'court_types']],
        'hearing_types' => ['fk' => []],
        'expense_categories' => ['fk' => []],
        'clients' => ['fk' => ['advocate_id' => 'users']],
        'cases' => ['fk' => ['client_id' => 'clients', 'court_id' => 'courts', 'assigned_to' => 'users']],
        'attorneys' => ['fk' => ['case_id' => 'cases', 'advocate_id' => 'users']],
        'hearings' => ['fk' => ['case_id' => 'cases', 'court_id' => 'courts', 'hearing_type' => 'hearing_types']],
        'tasks' => ['fk' => ['assigned_to' => 'users']],
        'invoices' => ['fk' => ['case_id' => 'cases', 'client_id' => 'clients']],
        'invoice_items' => ['fk' => ['invoice_id' => 'invoices']],
        'expenses' => ['fk' => ['case_id' => 'cases', 'category' => 'expense_categories', 'user_id' => 'users']],
        'payments' => ['fk' => ['invoice_id' => 'invoices']],
        'time_entries' => ['fk' => ['case_id' => 'cases', 'user_id' => 'users']],
        'trust_transactions' => ['fk' => ['client_id' => 'clients']],
        'documents' => ['fk' => ['case_id' => 'cases']],
        'document_accesses' => ['fk' => ['document_id' => 'documents', 'accessed_by' => 'users']],
    ];

    private IdMapper $idMapper;

    private string $inputDir;

    public function handle(): int
    {
        $input = $this->option('input');
        if (! $input || ! is_dir($input.'/tables')) {
            $this->error('Pass --input=/path/to/export/dir (the directory migration:export wrote, containing a tables/ subdirectory).');

            return CommandAlias::FAILURE;
        }

        $this->inputDir = $input.'/tables';
        $this->idMapper = new IdMapper;

        DB::transaction(function () {
            foreach (self::IMPORT_SPECS as $entity => $spec) {
                $this->importSimpleEntity($entity, $spec['fk']);
            }

            $this->importRolesAndPermissions();
            $this->importAudits();
        });

        $this->info('Import complete.');

        return CommandAlias::SUCCESS;
    }

    private function readExportedRows(string $entity): array
    {
        $path = "{$this->inputDir}/{$entity}.json";
        if (! file_exists($path)) {
            $this->warn("No export file for {$entity} ({$path}) — skipping.");

            return [];
        }

        return json_decode(file_get_contents($path), true) ?? [];
    }

    private function importSimpleEntity(string $entity, array $fkMap): void
    {
        $rows = $this->readExportedRows($entity);
        $imported = 0;

        foreach ($rows as $row) {
            $oldId = $row['id'];
            unset($row['id']);

            foreach ($fkMap as $column => $referencedEntity) {
                if (! array_key_exists($column, $row) || $row[$column] === null) {
                    continue;
                }

                $newRef = $this->idMapper->getNewId($referencedEntity, (int) $row[$column]);
                if ($newRef === null) {
                    // A genuinely orphaned reference that survived the
                    // §6.2 pre-flight scan somehow (or was deliberately
                    // left orphaned and mapped by hand) — set null rather
                    // than inserting a foreign key value that points
                    // nowhere in the destination, which would fail the
                    // table's own FK constraint outright.
                    $this->warn("{$entity}: row (old id {$oldId}) has no mapping for {$referencedEntity} via {$column} — setting null.");
                    $row[$column] = null;

                    continue;
                }

                $row[$column] = $newRef;
            }

            $newId = DB::table($entity)->insertGetId($row);
            $this->idMapper->record($entity, (int) $oldId, (int) $newId);
            $imported++;
        }

        $this->info("{$entity}: imported {$imported} row(s).");
    }

    /**
     * Spatie roles/permissions merge by name against whatever the
     * destination already seeded (RoleSeeder), rather than blind-insert
     * — see this command's own docblock for why. model_has_roles /
     * model_has_permissions / role_has_permissions then just need their
     * own FK columns remapped like any other pivot table, using the
     * name-based mapping just built instead of a blind id copy.
     */
    private function importRolesAndPermissions(): void
    {
        foreach (['roles', 'permissions'] as $entity) {
            $rows = $this->readExportedRows($entity);

            foreach ($rows as $row) {
                $oldId = $row['id'];
                $existingId = DB::table($entity)->where('name', $row['name'])->value('id');

                if ($existingId) {
                    $this->idMapper->record($entity, (int) $oldId, (int) $existingId);

                    continue;
                }

                unset($row['id']);
                $newId = DB::table($entity)->insertGetId($row);
                $this->idMapper->record($entity, (int) $oldId, (int) $newId);
            }
        }

        $pivotSpecs = [
            'model_has_roles' => ['role_id' => 'roles', 'model_id' => 'users'],
            'model_has_permissions' => ['permission_id' => 'permissions', 'model_id' => 'users'],
            'role_has_permissions' => ['permission_id' => 'permissions', 'role_id' => 'roles'],
        ];

        foreach ($pivotSpecs as $entity => $fkMap) {
            $rows = $this->readExportedRows($entity);
            $imported = 0;

            foreach ($rows as $row) {
                foreach ($fkMap as $column => $referencedEntity) {
                    if (! array_key_exists($column, $row) || $row[$column] === null) {
                        continue;
                    }
                    $row[$column] = $this->idMapper->getNewId($referencedEntity, (int) $row[$column]);
                }

                // These pivot tables have no surrogate id/timestamps of
                // their own (composite primary key) — insertOrIgnore
                // avoids a duplicate-key failure if the destination
                // already had the same admin/advocate/clerk role
                // assignment seeded.
                if (! in_array(null, $row, true)) {
                    DB::table($entity)->insertOrIgnore($row);
                    $imported++;
                }
            }

            $this->info("{$entity}: imported {$imported} row(s).");
        }
    }

    /**
     * Audits is polymorphic and must run last: every entity type it
     * could reference (per ForeignKeyRegistry::auditableTypeMap()) needs
     * its id mapping already fully populated.
     */
    private function importAudits(): void
    {
        $rows = $this->readExportedRows('audits');
        $auditableTypeMap = ForeignKeyRegistry::auditableTypeMap();
        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            unset($row['id']);

            $entityType = $auditableTypeMap[$row['auditable_type']] ?? null;
            if ($entityType && isset($row['auditable_id'])) {
                $newAuditableId = $this->idMapper->getNewId($entityType, (int) $row['auditable_id']);
                if ($newAuditableId === null) {
                    // The audited row itself didn't survive migration
                    // (e.g. a hard-deleted case pre-migration) — the
                    // audit entry has nothing left to point at, so it's
                    // skipped rather than inserted with a dangling
                    // auditable_id.
                    $skipped++;

                    continue;
                }
                $row['auditable_id'] = $newAuditableId;
            }

            if (isset($row['user_id'])) {
                $row['user_id'] = $this->idMapper->getNewId('users', (int) $row['user_id']);
            }

            DB::table('audits')->insert($row);
            $imported++;
        }

        $this->info("audits: imported {$imported} row(s), skipped {$skipped} (auditable row no longer exists).");
    }
}
