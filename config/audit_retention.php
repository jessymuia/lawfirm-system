<?php

/**
 * DATA-11: how long the audit trail is kept, and where it goes when it leaves the live table.
 *
 * Online: the months an audit row stays in the `audits` table (searchable in the app) is a plan entitlement,
 * `audit_online_months` (config/entitlements.php). Older months are archived by `audits:archive` to one gzipped
 * JSON-lines file per month under the firm's storage prefix, verified by SHA-256 before the rows are removed.
 *
 * Archives are kept for max(archive_years, floor_years) after their month, then deleted, never while the firm has
 * an active legal hold. floor_years is the professional-rules floor: it cannot be configured below. Set it to what
 * your regulator requires; 7 years is a common floor for legal records but is not legal advice.
 */
return [
    // unset: the firm's own files disk (TEN-2)
    'disk' => env('AUDIT_ARCHIVE_DISK'),
    'floor_years' => max(1, (int) env('AUDIT_RETENTION_FLOOR_YEARS', 7)),
    'archive_years' => (int) env('AUDIT_ARCHIVE_YEARS', 10),
    // rows per batch when reading or deleting a month
    'chunk' => 1000,
];
