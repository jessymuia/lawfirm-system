<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TEN-9: offboarding schedule and legal hold. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->boolean('legal_hold')->default(false)->after('status');
            $table->timestamp('offboarded_at')->nullable()->after('legal_hold');
            $table->timestamp('purge_after')->nullable()->after('offboarded_at');
            $table->timestamp('purged_at')->nullable()->after('purge_after');
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['legal_hold', 'offboarded_at', 'purge_after', 'purged_at']);
        });
    }
};
