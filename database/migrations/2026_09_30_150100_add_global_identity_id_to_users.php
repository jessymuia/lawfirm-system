<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-5: link from a tenant user to its control-plane identity. Deliberately
 * NOT a foreign key: identities live in another database, and a tenant dump
 * must stay restorable on its own (eject / restore). Nullable so self-hosted
 * installs and legacy rows are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('global_identity_id')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['global_identity_id']);
            $table->dropColumn('global_identity_id');
        });
    }
};
