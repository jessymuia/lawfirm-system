<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        // PLAT-8: the firm's support-access policy. Editable only by the firm (tenant admin); platform staff can read it, never change it.
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->text('support_policy')->nullable();
        });

        // One-time codes that turn an approved support session into a login on the tenant's own host.
        Schema::connection('control')->create('support_exchange_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('session_id');
            $table->char('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('support_exchange_codes');
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('support_policy');
        });
    }
};
