<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLAT-1: per-tenant entitlement overrides (JSON), layered over the tenant's plan. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->json('entitlements')->nullable()->after('plan');
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('entitlements');
        });
    }
};
