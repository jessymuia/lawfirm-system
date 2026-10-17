<?php

namespace App\Services\Migration;

/**
 * MIG-2/§3.3: the exact same set of foreign-key relationships needs
 * checking in two different places at two different times — the
 * pre-flight orphan scan (migration:orphan-scan, run on the OLD system
 * before export, per §6.2/§6.4's risk register) and the post-migration
 * verification suite (tests/Migration/MigrationVerificationTest.php, run
 * on the NEW system after import). Keeping one shared list here means
 * those two can never silently drift apart from each other as the
 * schema evolves.
 */
class ForeignKeyRegistry
{
    /**
     * @return list<array{table: string, column: string, references_table: string, references_column: string}>
     */
    public static function all(): array
    {
        return [
            ['table' => 'cases', 'column' => 'client_id', 'references_table' => 'clients', 'references_column' => 'id'],
            ['table' => 'cases', 'column' => 'court_id', 'references_table' => 'courts', 'references_column' => 'id'],
            ['table' => 'cases', 'column' => 'assigned_to', 'references_table' => 'users', 'references_column' => 'id'],
            ['table' => 'courts', 'column' => 'type', 'references_table' => 'court_types', 'references_column' => 'id'],
            ['table' => 'hearings', 'column' => 'case_id', 'references_table' => 'cases', 'references_column' => 'id'],
            ['table' => 'hearings', 'column' => 'court_id', 'references_table' => 'courts', 'references_column' => 'id'],
            ['table' => 'hearings', 'column' => 'hearing_type', 'references_table' => 'hearing_types', 'references_column' => 'id'],
            ['table' => 'invoices', 'column' => 'case_id', 'references_table' => 'cases', 'references_column' => 'id'],
            ['table' => 'invoices', 'column' => 'client_id', 'references_table' => 'clients', 'references_column' => 'id'],
            ['table' => 'invoice_items', 'column' => 'invoice_id', 'references_table' => 'invoices', 'references_column' => 'id'],
            ['table' => 'payments', 'column' => 'invoice_id', 'references_table' => 'invoices', 'references_column' => 'id'],
            ['table' => 'expenses', 'column' => 'case_id', 'references_table' => 'cases', 'references_column' => 'id'],
            ['table' => 'expenses', 'column' => 'category', 'references_table' => 'expense_categories', 'references_column' => 'id'],
            ['table' => 'expenses', 'column' => 'user_id', 'references_table' => 'users', 'references_column' => 'id'],
            ['table' => 'time_entries', 'column' => 'case_id', 'references_table' => 'cases', 'references_column' => 'id'],
            ['table' => 'time_entries', 'column' => 'user_id', 'references_table' => 'users', 'references_column' => 'id'],
            ['table' => 'trust_transactions', 'column' => 'client_id', 'references_table' => 'clients', 'references_column' => 'id'],
            ['table' => 'documents', 'column' => 'case_id', 'references_table' => 'cases', 'references_column' => 'id'],
            ['table' => 'document_accesses', 'column' => 'document_id', 'references_table' => 'documents', 'references_column' => 'id'],
            ['table' => 'document_accesses', 'column' => 'accessed_by', 'references_table' => 'users', 'references_column' => 'id'],
            ['table' => 'attorneys', 'column' => 'case_id', 'references_table' => 'cases', 'references_column' => 'id'],
            ['table' => 'attorneys', 'column' => 'advocate_id', 'references_table' => 'users', 'references_column' => 'id'],
            ['table' => 'tasks', 'column' => 'assigned_to', 'references_table' => 'users', 'references_column' => 'id'],
        ];
    }

    /**
     * Polymorphic audits.auditable_id needs type-aware dispatch rather
     * than a flat FK entry — auditable_type => the table it points into.
     *
     * @return array<string, string>
     */
    public static function auditableTypeMap(): array
    {
        return [
            'App\\Models\\Cases' => 'cases',
            'App\\Models\\Client' => 'clients',
            'App\\Models\\Hearing' => 'hearings',
            'App\\Models\\Invoice' => 'invoices',
            'App\\Models\\Expense' => 'expenses',
        ];
    }
}
