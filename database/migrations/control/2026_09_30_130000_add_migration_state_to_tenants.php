<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TEN-7: per-tenant migration state so partial rollouts are visible and retryable. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->string('migration_status', 16)->default('pending')->after('schema_version'); // pending|running|ok|failed
            $table->text('migration_error')->nullable()->after('migration_status');
            $table->timestamp('migration_attempted_at')->nullable()->after('migration_error');
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['migration_status', 'migration_error', 'migration_attempted_at']);
        });
    }
};
