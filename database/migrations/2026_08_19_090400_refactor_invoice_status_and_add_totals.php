<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DATA-3: the generic `status` tinyint was overloaded with undocumented
     * magic numbers (1=active, 3=draft, 4=deleted-by-admin, 5=deleted-by-user)
     * that mean something different here than in every other module, and the
     * invoice had no invoice number, totals, payment tracking or currency.
     *
     * This migration:
     *  - adds a dedicated, named `workflow_status` column for the invoice's
     *    draft/submitted/void lifecycle, decoupled from the generic `status`
     *    tinyint every other module uses for its own (unrelated) purpose;
     *  - adds `invoice_number`, `currency`, cached totals, and payment
     *    tracking columns;
     *  - backfills every existing row from the old magic numbers so no data
     *    is lost, then leaves the legacy `status` column alone (untouched,
     *    still defaulted to 1) since other modules still rely on it.
     */
    public function up()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_number')->nullable()->after('id');
            $table->string('currency', 3)->default('KES')->after('client_id');
            $table->enum('workflow_status', ['draft', 'submitted', 'void'])->default('draft')->after('currency');
            $table->enum('payment_status', ['unpaid', 'partially_paid', 'paid'])->default('unpaid')->after('workflow_status');
            $table->decimal('subtotal', 12, 2)->default(0)->after('payment_status');
            $table->decimal('tax_total', 12, 2)->default(0)->after('subtotal');
            $table->decimal('total_amount', 12, 2)->default(0)->after('tax_total');
            $table->decimal('amount_paid', 12, 2)->default(0)->after('total_amount');
        });

        // Backfill workflow_status from the old magic-number `status` column.
        DB::table('invoices')->where('status', 1)->update(['workflow_status' => 'submitted']);
        DB::table('invoices')->where('status', 3)->update(['workflow_status' => 'draft']);
        DB::table('invoices')->whereIn('status', [4, 5])->update(['workflow_status' => 'void']);

        // Backfill invoice_number sequentially from id, oldest first, so
        // every existing row gets a stable, human-readable number.
        DB::table('invoices')->orderBy('id')->pluck('id')->each(function ($id) {
            DB::table('invoices')->where('id', $id)->update([
                'invoice_number' => 'INV-'.str_pad($id, 6, '0', STR_PAD_LEFT),
            ]);
        });

        // Backfill cached totals from existing invoice_items.
        $totals = DB::table('invoice_items')
            ->select('invoice_id')
            ->selectRaw('SUM(rate * quantity) as subtotal, SUM(tax) as tax_total, SUM(total_amount) as total_amount')
            ->groupBy('invoice_id')
            ->get();

        foreach ($totals as $row) {
            DB::table('invoices')->where('id', $row->invoice_id)->update([
                'subtotal' => $row->subtotal ?? 0,
                'tax_total' => $row->tax_total ?? 0,
                'total_amount' => $row->total_amount ?? 0,
            ]);
        }

        Schema::table('invoices', function (Blueprint $table) {
            $table->unique(['invoice_number', 'deleted_at']);
        });
    }

    public function down()
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['invoice_number', 'deleted_at']);
            $table->dropColumn([
                'invoice_number',
                'currency',
                'workflow_status',
                'payment_status',
                'subtotal',
                'tax_total',
                'total_amount',
                'amount_paid',
            ]);
        });
    }
};
