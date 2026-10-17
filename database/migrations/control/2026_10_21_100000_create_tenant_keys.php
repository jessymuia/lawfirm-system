<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-13: each firm's data key, never stored in the clear. `wrapped_key` is the data key encrypted by the platform's
 * master key (`provider` platform) or by the firm's own AWS KMS key (`provider` aws-kms, `key_ref` its ARN). Destroying
 * the firm's key empties `wrapped_key` and stamps `destroyed_at`; the row stays as the record that it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('tenant_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $t->string('provider', 20);
            $t->string('key_ref', 2048)->nullable();
            $t->text('wrapped_key')->nullable();
            $t->string('fingerprint', 64);
            $t->timestamp('rewrapped_at')->nullable();
            $t->timestamp('destroyed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('tenant_keys');
    }
};
