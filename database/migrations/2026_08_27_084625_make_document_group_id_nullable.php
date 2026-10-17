<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bug fix (found via a real end-to-end run, not a code review — this
     * was invisible to static reading and broke every single new
     * document upload): the FUN-5 versioning migration
     * (2026_08_19_140000_add_versioning_to_documents.php) intentionally
     * backfilled document_group_id for existing rows and then made the
     * column NOT NULL. But DocumentController::store() creates a new,
     * non-versioned document in two steps — insert the row first, then
     * update() it to set document_group_id to its own freshly-generated
     * id (a document is its own group of one until a second version is
     * uploaded against it) — because the id isn't known until after the
     * insert. That insert has never been able to include
     * document_group_id, so with the column NOT NULL every such insert
     * failed at the database level with a hard SQL error, 500ing the
     * request before the controller's own self-referencing update() ever
     * ran.
     *
     * The fix is schema-side: making the column nullable again restores
     * the brief, intentional gap between "row exists" and "row knows its
     * own group id" that the controller's logic already depends on and
     * correctly handles. No application code changes — the controller's
     * self-referencing update() was always correct, it just never got a
     * chance to run.
     */
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_group_id')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_group_id')->nullable(false)->change();
        });
    }
};
