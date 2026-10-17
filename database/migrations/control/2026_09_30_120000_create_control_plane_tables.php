<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-2: control-plane schema. Run with:
 *   php artisan migrate --database=control --path=database/migrations/control
 * Rule: if it could ever join to a case, it does not belong here.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 63)->unique();       // also the TenantContext key
            $table->string('name');
            $table->string('status', 20)->default('provisioning'); // provisioning|active|suspended|offboarded
            $table->string('region', 32)->nullable();
            $table->string('plan', 32)->nullable();
            $table->string('cell', 32)->default('default');
            $table->string('database');                 // tenant database name on its cell
            $table->string('schema_version', 64)->nullable(); // last applied tenant migration
            $table->timestamps();
        });

        Schema::connection('control')->create('tenant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('domain')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('tenant_domains');
        Schema::connection('control')->dropIfExists('tenants');
    }
};
