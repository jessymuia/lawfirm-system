<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-6: cases had no lifecycle status — only the generic `status`
     * tinyint shared with every other module. Adds a dedicated,
     * named lifecycle column, defaulting existing cases to 'open'.
     */
    public function up()
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->enum('lifecycle_status', ['open', 'closed', 'appeal', 'settled'])
                ->default('open')
                ->after('opposing_party');
        });
    }

    public function down()
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropColumn('lifecycle_status');
        });
    }
};
