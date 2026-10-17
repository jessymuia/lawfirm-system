<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DATA-10: ledger immutability enforced by the database, below the app layer,
 * so it holds for buggy code, ad-hoc scripts and support consoles alike, and
 * can be demonstrated to an auditor.
 *
 * trust_transactions   never deleted; financial columns immutable; the only
 *                      permitted change is the one-way void marker.
 * payments             never hard-deleted; financial columns immutable; the
 *                      only permitted change is a one-way soft void.
 * invoices             never hard-deleted; invoice number immutable; once
 *                      issued, amounts/client/case/dates are frozen and the
 *                      workflow may only move draft -> submitted -> void.
 * invoice_items        frozen once the parent invoice is no longer a draft.
 *
 * Corrections are made by voiding (a marked, permanent, audited state) and
 * posting a new entry, never by editing history.
 *
 * Supports MySQL/MariaDB and SQLite; any other driver fails loudly rather
 * than silently running without enforcement.
 */
return new class extends Migration
{
    private const TRIGGER_PREFIX = 'ledger_guard_';

    public function up(): void
    {
        $driver = DB::getDriverName();
        if (! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new RuntimeException("DATA-10 ledger triggers are not implemented for the [{$driver}] driver.");
        }

        foreach ($this->rules() as $i => [$table, $event, $condition, $message]) {
            $name = self::TRIGGER_PREFIX.$i;
            DB::unprepared($this->createTrigger($driver, $name, $table, $event, $condition, $message));
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->rules()) as $i) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::TRIGGER_PREFIX.$i);
        }
    }

    /** @return array<int, array{0:string,1:string,2:string,3:string}> table, event, SQL condition that must never hold, message */
    private function rules(): array
    {
        $driver = DB::getDriverName();
        $changed = fn (string $c) => $driver === 'sqlite'
            ? "OLD.{$c} IS NOT NEW.{$c}"
            : "NOT (OLD.{$c} <=> NEW.{$c})";
        $anyChanged = fn (array $cols) => '('.implode(' OR ', array_map($changed, $cols)).')';
        $parentNotDraft = fn (string $ref) => "(SELECT workflow_status FROM invoices WHERE id = {$ref}.invoice_id) <> 'draft'";

        return [
            // ---- trust_transactions ----
            ['trust_transactions', 'DELETE', '1 = 1', 'trust_transactions is append-only: rows cannot be deleted'],
            ['trust_transactions', 'UPDATE',
                $anyChanged(['client_id', 'case_id', 'type', 'amount', 'balance_after', 'description', 'reference_number', 'recorded_by', 'created_by', 'created_at', 'deleted_at', 'deleted_by']),
                'trust_transactions is append-only: posted entries cannot be edited (void and re-post instead)'],
            ['trust_transactions', 'UPDATE',
                'OLD.voided_at IS NOT NULL AND '.$anyChanged(['voided_at', 'voided_by']),
                'trust_transactions: a voided entry cannot be altered or un-voided'],

            // ---- payments ----
            ['payments', 'DELETE', '1 = 1', 'payments is append-only: rows cannot be deleted (void instead)'],
            ['payments', 'UPDATE',
                $anyChanged(['invoice_id', 'receipt_number', 'amount', 'payment_date', 'method', 'reference_number', 'received_by', 'notes', 'created_by', 'created_at']),
                'payments is append-only: a recorded receipt cannot be edited (void and re-record instead)'],
            ['payments', 'UPDATE',
                'OLD.deleted_at IS NOT NULL AND '.$anyChanged(['deleted_at', 'deleted_by']),
                'payments: a voided payment cannot be altered or restored'],

            // ---- invoices ----
            ['invoices', 'DELETE', '1 = 1', 'invoices is append-only: rows cannot be deleted (void instead)'],
            ['invoices', 'UPDATE',
                'OLD.invoice_number IS NOT NULL AND '.$changed('invoice_number'),
                'invoices: an issued invoice number can never change'],
            ['invoices', 'UPDATE',
                "OLD.workflow_status <> 'draft' AND ".$anyChanged(['subtotal', 'tax_total', 'total_amount', 'client_id', 'case_id', 'currency', 'invoice_date', 'invoice_due_date']),
                'invoices: a submitted or void invoice is frozen (amounts, client, case, dates)'],
            ['invoices', 'UPDATE',
                $changed('workflow_status')." AND NOT (OLD.workflow_status = 'draft' AND NEW.workflow_status IN ('submitted', 'void')) AND NOT (OLD.workflow_status = 'submitted' AND NEW.workflow_status = 'void')",
                'invoices: workflow may only move draft -> submitted -> void'],

            // ---- invoice_items ----
            ['invoice_items', 'INSERT', $parentNotDraft('NEW'), 'invoice_items: items cannot be added to a submitted or void invoice'],
            ['invoice_items', 'UPDATE', $parentNotDraft('OLD').' OR '.$parentNotDraft('NEW'), 'invoice_items: items of a submitted or void invoice are frozen'],
            ['invoice_items', 'DELETE', $parentNotDraft('OLD'), 'invoice_items: items of a submitted or void invoice cannot be deleted'],
        ];
    }

    private function createTrigger(string $driver, string $name, string $table, string $event, string $condition, string $message): string
    {
        $message = str_replace("'", "''", $message);

        if ($driver === 'sqlite') {
            return "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW WHEN {$condition} "
                ."BEGIN SELECT RAISE(ABORT, '{$message}'); END";
        }

        return "CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW "
            ."BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END";
    }
};
