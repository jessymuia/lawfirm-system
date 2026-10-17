<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLAT-3: per-tenant feature-flag overrides ({"flag": true|false}); absent = follow edition/default. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->json('flags')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('flags');
        });
    }
};
