<?php

/**
 * CARRY-4 (MIG-3): what every coded value in the legacy database means, and what it becomes after the cutover. This is
 * the machine-readable form of docs/ops/MIGRATION_STATUS_MAPPING.md, and it mirrors what the upgrade migrations do
 * (2026_08_19_090400 for invoices). `php artisan migration:status-report` compares it with the real production data and
 * writes the sign-off sheet; any value found in production that is not listed here stops the cutover.
 *
 * A value is signed off when someone who knows the old system confirms its meaning on the sheet. Change this file (and
 * the migration, if the target changes) before the cutover, never during it.
 */
return [
    'columns' => [
        // DATA-3: the only documented magic numbers. The upgrade fills invoices.workflow_status from them.
        'invoices.status' => [
            'values' => [
                1 => ['meaning' => 'Active (sent to the client)', 'becomes' => 'workflow_status = submitted'],
                3 => ['meaning' => 'Draft', 'becomes' => 'workflow_status = draft'],
                4 => ['meaning' => 'Deleted by an administrator', 'becomes' => 'workflow_status = void (stays visible as void, not soft-deleted)'],
                5 => ['meaning' => 'Deleted by its creator', 'becomes' => 'workflow_status = void (stays visible as void, not soft-deleted)'],
            ],
            'question' => 'Should invoices 4 and 5 stay visible as void, or be hidden (soft-deleted)? Is status 1 "submitted for approval" or "sent to the client"?',
        ],

        // Every other table built with Utils::createDefaultTableColumns has these two generic flags. The new system
        // does not read `status` at all, and reads `archive` only to hide archived hearing types from pickers.
        '*.status' => [
            'values' => [
                1 => ['meaning' => 'Active (the column default)', 'becomes' => 'unchanged; the new system ignores this column'],
            ],
            'question' => 'Any other value would have hidden or disabled the row in the old system; after the cutover such rows show as ordinary records. Confirm, or decide to archive or soft-delete them.',
        ],
        '*.archive' => [
            'values' => [
                0 => ['meaning' => 'Not archived (the column default)', 'becomes' => 'unchanged'],
                1 => ['meaning' => 'Archived', 'becomes' => 'unchanged; archived hearing types stay out of pickers, other archived rows show as ordinary records'],
            ],
            'question' => 'Archived clients, matters and other records show as ordinary records after the cutover. Confirm.',
        ],

        // Enum columns: the new system refuses anything outside its enums (App\Enums, DATA-12; a CHECK on MySQL).
        'tasks.priority' => [
            'values' => [
                'high' => ['meaning' => 'High', 'becomes' => 'high'],
                'medium' => ['meaning' => 'Medium (default)', 'becomes' => 'medium'],
                'low' => ['meaning' => 'Low', 'becomes' => 'low'],
            ],
        ],
        'tasks.task_status' => [
            'values' => [
                'pending' => ['meaning' => 'Pending', 'becomes' => 'pending'],
                'in_progress' => ['meaning' => 'In progress', 'becomes' => 'in_progress'],
                'completed' => ['meaning' => 'Completed', 'becomes' => 'completed'],
                'overdue' => ['meaning' => 'Overdue', 'becomes' => 'overdue'],
            ],
        ],
    ],

    // Tables whose `status` is NOT the generic flag (they carry their own meaning above).
    'specific_status_tables' => ['invoices'],
];
