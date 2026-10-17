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
        Schema::create('documents', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('case_id')->constrained('cases');
            $table->string('title');
            $table->string('filename');
            $table->string('filepath');
            $table->string('full_path');
            $table->string('mimetype');
            $table->double('filesize');
            $table->string('extension');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('documents');
    }
};
