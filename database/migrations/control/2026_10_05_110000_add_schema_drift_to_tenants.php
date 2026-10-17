<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TEN-8: the last schema-drift check per firm (null drift = clean). Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->json('schema_drift')->nullable();
            $table->timestamp('drift_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['schema_drift', 'drift_checked_at']);
        });
    }
};
