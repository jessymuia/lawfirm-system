<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-2: no time tracking / billable hours existed at all. This table
     * records billable (and non-billable) work against a case, which can
     * later be pulled into an invoice as line items.
     */
    public function up()
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('case_id')->constrained('cases');
            $table->foreignId('user_id')->constrained('users');
            $table->string('description');
            $table->date('date');
            $table->decimal('hours', 6, 2);
            $table->decimal('hourly_rate', 12, 2);
            $table->boolean('billable')->default(true);
            $table->boolean('billed')->default(false);
            $table->foreignId('invoice_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
        });
    }

    public function down()
    {
        Schema::dropIfExists('time_entries');
    }
};
