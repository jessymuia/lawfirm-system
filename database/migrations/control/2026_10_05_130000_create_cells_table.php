<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-4: the cell registry. A cell is one database server (or cluster/proxy endpoint) holding a few hundred firm
 * databases. Capacity grows by adding a row here (`cells add`), never by changing code. A firm's `tenants.cell` names
 * its cell; a firm whose cell has no row uses the tenant connection template unchanged (the single-cell default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('cells', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->string('region', 32)->nullable();
            // Overrides on top of the tenant connection template; null = the template's own value.
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('username')->nullable();
            $table->string('password_env', 64)->nullable();   // NAME of an env var / *_FILE secret, never the password
            $table->string('sqlite_directory')->nullable();     // SQLite deployments/tests: where this cell's files live
            $table->unsignedInteger('capacity')->default(300);  // firms per cell before it stops accepting new ones
            $table->boolean('accepting')->default(true);        // false = full or draining; existing firms stay
            $table->timestamps();
        });

        // TEN-16: while a firm is being moved between cells it is read-only (writes answer 503 with this reason).
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->string('read_only_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn('read_only_reason');
        });
        Schema::connection('control')->dropIfExists('cells');
    }
};
