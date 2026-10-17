<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-5: one login (identity) can belong to several firms. The control plane
 * holds only who a person is and which firms they belong to; the firm-level
 * profile, roles and audit trail stay in each tenant's own `users` table.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('identities', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();          // stored lowercase
            $table->string('password');
            $table->string('status', 16)->default('active'); // active|disabled
            $table->timestamps();
        });

        Schema::connection('control')->create('tenant_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('identity_id')->constrained('identities')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('tenant_user_id'); // users.id inside that tenant's database
            $table->timestamps();

            $table->unique(['identity_id', 'tenant_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('tenant_memberships');
        Schema::connection('control')->dropIfExists('identities');
    }
};
