<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** ENG-24: per-tenant usage, one row per tenant/day/metric. Also the basis for PLAT-4 metering. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('tenant_usage_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->date('day');
            $table->string('metric', 40);
            $table->unsignedBigInteger('value')->default(0);
            $table->timestamps();
            $table->unique(['tenant_id', 'day', 'metric']);
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('tenant_usage_daily');
    }
};
