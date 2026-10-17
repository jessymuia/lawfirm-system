<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-13: UTBMS codes on billable work and the identifiers a LEDES e-bill needs.
 *  - time_entries: task + activity code; expenses: expense code; invoice_items: all three (copied from the time entry
 *    when time is billed, or chosen on a manual line).
 *  - clients.ebilling_client_id: the id the client's e-billing system knows the firm's client by (optional).
 *  - cases.client_matter_id: the client's own reference for the matter (many e-billing systems require it).
 *  - users.timekeeper_class: LEDES timekeeper classification (PT, AS, OC, LA, PL, OT).
 * Additive, nullable columns only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->string('utbms_task_code', 8)->nullable();
            $table->string('utbms_activity_code', 8)->nullable();
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('utbms_expense_code', 8)->nullable();
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->string('utbms_task_code', 8)->nullable();
            $table->string('utbms_activity_code', 8)->nullable();
            $table->string('utbms_expense_code', 8)->nullable();
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->string('ebilling_client_id', 64)->nullable();
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->string('client_matter_id', 64)->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('timekeeper_class', 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('timekeeper_class'));
        Schema::table('cases', fn (Blueprint $t) => $t->dropColumn('client_matter_id'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('ebilling_client_id'));
        Schema::table('invoice_items', fn (Blueprint $t) => $t->dropColumn(['utbms_task_code', 'utbms_activity_code', 'utbms_expense_code']));
        Schema::table('expenses', fn (Blueprint $t) => $t->dropColumn('utbms_expense_code'));
        Schema::table('time_entries', fn (Blueprint $t) => $t->dropColumn(['utbms_task_code', 'utbms_activity_code']));
    }
};
