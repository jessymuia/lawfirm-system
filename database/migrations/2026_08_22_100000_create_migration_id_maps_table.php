<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MIG-4/§6.3 Phase 2: every entity gets a new auto-increment id on
     * import (the destination database is not guaranteed to be empty —
     * see MigrationImport's own docblock), so every FK reference and
     * every audits.auditable_id/user_id must be rewritten from the
     * source system's id to whatever id that row actually landed at
     * here. One shared table (rather than one per entity) keyed by
     * (entity_type, old_id) — simpler to migrate itself, and the volume
     * here is small enough that a single table's index performs fine.
     *
     * This table is migration tooling, not application data — it's
     * never read by the running app, only by
     * App\Services\Migration\IdMapper during the Phase 2 import command
     * and Phase 2's own audits-rewrite step. Safe to drop once cutover
     * (§6.3 Phase 4) is complete and the retention window has passed.
     */
    public function up()
    {
        Schema::create('migration_id_maps', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type'); // e.g. 'clients', 'cases', 'users'
            $table->unsignedBigInteger('old_id');
            $table->unsignedBigInteger('new_id');
            $table->timestamps();

            $table->unique(['entity_type', 'old_id']);
            $table->index(['entity_type', 'new_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('migration_id_maps');
    }
};
