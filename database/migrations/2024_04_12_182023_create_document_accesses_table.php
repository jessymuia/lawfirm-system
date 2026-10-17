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
        Schema::create('document_accesses', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('document_id')->constrained('documents');
            $table->foreignId('accessed_by')->constrained('users');
            $table->date('accessed_date');
            $table->string('action');
            $table->ipAddress('ip_address');
            $table->string('outcome');
            $table->string('device_info');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('document_accesses');
    }
};
