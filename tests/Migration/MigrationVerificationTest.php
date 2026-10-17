<?php

namespace Tests\Migration;

use App\Services\Migration\ForeignKeyRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * §3.3 Migration verification tests. Run during §6 Phase 3, pointed at
 * the real pre-migration ('source') database and the freshly-migrated
 * default ('destination') connection:
 *
 *   php artisan test --testsuite=Migration
 *
 * Every test here skips itself (see MigrationTestCase) if no source
 * database is configured, so this never runs — and never gives false
 * confidence — as part of ordinary CI.
 */
class MigrationVerificationTest extends MigrationTestCase
{
    /**
     * Domain tables carried across the migration, per §6.1's inventory —
     * deliberately excludes Laravel/Sanctum infrastructure tables
     * (jobs, failed_jobs, password_resets, personal_access_tokens) that
     * don't represent migrated business data and are expected to differ.
     */
    private const MIGRATED_TABLES = [
        'users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions',
        'court_types', 'courts', 'hearing_types', 'expense_categories',
        'clients', 'cases', 'attorneys', 'hearings', 'tasks',
        'invoices', 'invoice_items', 'expenses', 'payments', 'time_entries', 'trust_transactions',
        'documents', 'document_accesses', 'audits',
    ];

    /** How many rows in a table to consider "reasonable to sample" for the auth check. */
    private const USER_SAMPLE_SIZE = 20;

    public function test_row_counts_per_table_match_including_soft_deleted_rows(): void
    {
        $mismatches = [];

        foreach (self::MIGRATED_TABLES as $table) {
            if (! $this->destinationConnection()->getSchemaBuilder()->hasTable($table)) {
                $mismatches[] = "{$table}: does not exist in the destination database at all";

                continue;
            }
            if (! $this->sourceConnection()->getSchemaBuilder()->hasTable($table)) {
                // Some tables (time_entries, trust_transactions, payments)
                // are new in this system and legitimately won't exist in
                // an older source database — that's expected, not a
                // migration defect, so it's skipped rather than failed.
                continue;
            }

            // Raw DB::table()->count() includes soft-deleted rows (no
            // Eloquent global scope is applied), which is exactly what
            // §6.3 asks this check to cover.
            $sourceCount = $this->sourceConnection()->table($table)->count();
            $destinationCount = $this->destinationConnection()->table($table)->count();

            if ($sourceCount !== $destinationCount) {
                $mismatches[] = "{$table}: source has {$sourceCount} rows, destination has {$destinationCount}";
            }
        }

        $this->assertEmpty($mismatches, "Row count mismatches found:\n".implode("\n", $mismatches));
    }

    public function test_zero_orphaned_foreign_keys_in_the_destination_database(): void
    {
        $orphanReports = [];

        foreach (ForeignKeyRegistry::all() as $fk) {
            if (! $this->destinationConnection()->getSchemaBuilder()->hasTable($fk['table'])) {
                continue;
            }

            $orphanCount = $this->destinationConnection()
                ->table($fk['table'].' as t')
                ->whereNotNull("t.{$fk['column']}")
                ->whereNotExists(function ($query) use ($fk) {
                    $query->select(DB::raw(1))
                        ->from($fk['references_table'].' as r')
                        ->whereColumn("r.{$fk['references_column']}", "t.{$fk['column']}");
                })
                ->count();

            if ($orphanCount > 0) {
                $orphanReports[] = "{$fk['table']}.{$fk['column']}: {$orphanCount} row(s) reference a non-existent {$fk['references_table']}.{$fk['references_column']}";
            }
        }

        // Polymorphic audits.auditable_id — checked per concrete type
        // rather than generically, since the referenced table depends on
        // auditable_type.
        if ($this->destinationConnection()->getSchemaBuilder()->hasTable('audits')) {
            $auditableTypeToTable = ForeignKeyRegistry::auditableTypeMap();

            foreach ($auditableTypeToTable as $auditableType => $table) {
                if (! $this->destinationConnection()->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }

                $orphanCount = $this->destinationConnection()
                    ->table('audits as a')
                    ->where('a.auditable_type', $auditableType)
                    ->whereNotExists(function ($query) use ($table) {
                        $query->select(DB::raw(1))
                            ->from("{$table} as r")
                            ->whereColumn('r.id', 'a.auditable_id');
                    })
                    ->count();

                if ($orphanCount > 0) {
                    $orphanReports[] = "audits: {$orphanCount} row(s) reference a non-existent {$auditableType} (auditable_id)";
                }
            }
        }

        $this->assertEmpty($orphanReports, "Orphaned foreign keys found:\n".implode("\n", $orphanReports));
    }

    public function test_invoice_sums_reconcile_to_the_cent(): void
    {
        if (! $this->destinationConnection()->getSchemaBuilder()->hasTable('invoice_items')) {
            $this->markTestSkipped('invoice_items table not present.');
        }

        // Internal reconciliation: every line item's own total must equal
        // quantity*rate + tax exactly (regression coverage for the tax
        // calculation bug found and fixed during the §3.2 E2E work — see
        // InvoiceItemController's own comments). A migration that copied
        // pre-bug-fix data verbatim would surface exactly here.
        $items = $this->destinationConnection()->table('invoice_items')->whereNull('deleted_at')->get();
        $lineItemMismatches = [];

        foreach ($items as $item) {
            $expected = round(((float) $item->quantity * (float) $item->rate) + (float) $item->tax, 2);
            $actual = round((float) $item->total_amount, 2);

            if (abs($expected - $actual) > 0.01) {
                $lineItemMismatches[] = "invoice_item #{$item->id}: expected {$expected}, got {$actual}";
            }
        }

        $this->assertEmpty(
            $lineItemMismatches,
            "Invoice line item totals don't reconcile to quantity*rate+tax:\n".implode("\n", $lineItemMismatches)
        );

        // Invoice-level aggregate: subtotal/tax_total/total_amount must
        // match what the line items actually sum to, to the cent.
        $invoices = $this->destinationConnection()->table('invoices')->whereNull('deleted_at')->get();
        $invoiceMismatches = [];

        foreach ($invoices as $invoice) {
            $lineItemTotals = $this->destinationConnection()
                ->table('invoice_items')
                ->where('invoice_id', $invoice->id)
                ->whereNull('deleted_at')
                ->selectRaw('COALESCE(SUM(rate * quantity), 0) as subtotal, COALESCE(SUM(tax), 0) as tax_total, COALESCE(SUM(total_amount), 0) as total_amount')
                ->first();

            if (abs(round((float) $invoice->subtotal, 2) - round((float) $lineItemTotals->subtotal, 2)) > 0.01
                || abs(round((float) $invoice->tax_total, 2) - round((float) $lineItemTotals->tax_total, 2)) > 0.01
                || abs(round((float) $invoice->total_amount, 2) - round((float) $lineItemTotals->total_amount, 2)) > 0.01
            ) {
                $invoiceMismatches[] = "invoice #{$invoice->id} ({$invoice->invoice_number}): stored totals don't match its own line items";
            }
        }

        $this->assertEmpty(
            $invoiceMismatches,
            "Invoice-level totals don't reconcile with their line items:\n".implode("\n", $invoiceMismatches)
        );

        // Firm-wide aggregate comparison against source, if the source
        // database actually has comparable columns (older schemas may
        // predate DATA-3/DATA-4's status/decimal fixes entirely, in which
        // case a direct sum comparison isn't meaningful and is skipped
        // rather than falsely failed).
        if ($this->sourceConnection()->getSchemaBuilder()->hasColumn('invoices', 'total_amount')) {
            $sourceTotal = round((float) $this->sourceConnection()->table('invoices')->sum('total_amount'), 2);
            $destinationTotal = round((float) $this->destinationConnection()->table('invoices')->sum('total_amount'), 2);

            $this->assertEqualsWithDelta(
                $sourceTotal,
                $destinationTotal,
                0.01,
                "Firm-wide invoice total_amount sum differs: source={$sourceTotal}, destination={$destinationTotal}"
            );
        }
    }

    public function test_document_checksums_match_the_export_manifest(): void
    {
        $manifestPath = config('services.migration.checksum_manifest_path');

        if (! $manifestPath || ! file_exists($manifestPath)) {
            $this->markTestSkipped(
                'No checksum manifest available — run `php artisan migration:document-checksums` '.
                'in the source system during §6 Phase 1 first, then point '.
                'MIGRATION_CHECKSUM_MANIFEST_PATH at that file for this check.'
            );
        }

        $exitCode = Artisan::call('migration:document-checksums', [
            '--manifest' => $manifestPath,
            '--verify' => true,
        ]);

        $this->assertSame(
            0,
            $exitCode,
            "Document checksum verification failed:\n".Artisan::output()
        );
    }

    public function test_sampled_users_have_intact_authenticatable_password_hashes(): void
    {
        $sourceUsers = $this->sourceConnection()
            ->table('users')
            ->inRandomOrder()
            ->limit(self::USER_SAMPLE_SIZE)
            ->get(['id', 'email', 'password']);

        $this->assertNotEmpty($sourceUsers, 'No users found in the source database to sample.');

        $problems = [];

        foreach ($sourceUsers as $sourceUser) {
            $destinationUser = $this->destinationConnection()
                ->table('users')
                ->where('email', $sourceUser->email)
                ->first(['id', 'password']);

            if (! $destinationUser) {
                $problems[] = "user {$sourceUser->email}: not found in destination at all";

                continue;
            }

            // Passwords migrate as-is (§6.1: "bcrypt hashes ... migrate
            // as-is") — never re-hashed in transit. The hash string must
            // be byte-identical, not just "some valid-looking hash",
            // since even a correctly-formatted hash that differs from
            // the original would mean the user's actual password changed
            // during migration.
            if ($destinationUser->password !== $sourceUser->password) {
                $problems[] = "user {$sourceUser->email}: password hash differs between source and destination";

                continue;
            }

            // Sanity check on the hash format itself — a truncated or
            // corrupted-in-transit hash could still happen to match
            // byte-for-byte if both sides were corrupted identically
            // (e.g. a shared encoding bug), so this checks the hash is
            // actually a well-formed bcrypt hash independent of the
            // equality check above.
            if (! preg_match('/^\$2[axy]\$\d{2}\$.{53}$/', $destinationUser->password)) {
                $problems[] = "user {$sourceUser->email}: destination password is not a well-formed bcrypt hash";
            }
        }

        $this->assertEmpty(
            $problems,
            "Sampled users failed authentication-integrity checks:\n".implode("\n", $problems)
        );
    }
}
