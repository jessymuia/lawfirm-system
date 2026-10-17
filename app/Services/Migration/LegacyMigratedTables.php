<?php

namespace App\Services\Migration;

/** The legacy domain tables (§6.1 of the migration plan), counted before and after the cutover. */
final class LegacyMigratedTables
{
    public const TABLES = [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'court_types', 'courts', 'hearing_types', 'expense_categories',
        'clients', 'cases', 'attorneys', 'hearings', 'tasks',
        'invoices', 'invoice_items', 'expenses', 'documents', 'document_accesses', 'audits',
    ];

    /** Reference tables the upgrade fills from the permission and system catalogs: rows may be added, never lost. */
    public const CATALOG = ['roles', 'permissions', 'role_has_permissions', 'model_has_permissions', 'court_types', 'courts', 'hearing_types', 'expense_categories'];
}
