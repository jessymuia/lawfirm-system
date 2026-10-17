<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->string('description');
            $table->double('quantity');
            $table->double('rate');
            $table->double('tax');
            $table->double('total_amount');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('invoice_items');
    }
};
