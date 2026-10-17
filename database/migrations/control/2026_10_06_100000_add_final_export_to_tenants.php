<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TEN-9: the firm's final export (made at offboarding, kept through the retention window) and the purge report. Additive only. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->string('final_export_path')->nullable();
            $table->string('final_export_sha256', 64)->nullable();
            $table->timestamp('final_export_at')->nullable();
            $table->text('final_export_error')->nullable();
            $table->json('purge_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['final_export_path', 'final_export_sha256', 'final_export_at', 'final_export_error', 'purge_report']);
        });
    }
};
