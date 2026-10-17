<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-5: documents had no versioning and lived only on local disk with
     * no backup story (S3 was configured but unused).
     *
     * - `document_group_id` links every version of the same logical
     *   document together; it points at the *first* version's id, so the
     *   group id is stable and never changes as new versions are added.
     * - `version` is a simple incrementing counter within the group.
     * - `is_current` marks the single active version per group (queries
     *   for "the document" filter on this rather than always taking the
     *   max version, which is cheaper and clearer).
     * - `disk` records which filesystem disk (`local` or `s3`) the file
     *   actually lives on, since existing uploads stay on local while new
     *   ones move to S3 — a single global "current disk" setting can't
     *   describe a table with files on both.
     */
    public function up()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_group_id')->nullable()->after('case_id');
            $table->unsignedInteger('version')->default(1)->after('document_group_id');
            $table->boolean('is_current')->default(true)->after('version');
            $table->string('disk', 20)->default('local')->after('full_path');
        });

        // Every existing document is its own group of one, version 1.
        DB::table('documents')->orderBy('id')->pluck('id')->each(function ($id) {
            DB::table('documents')->where('id', $id)->update(['document_group_id' => $id]);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('document_group_id')->nullable(false)->change();
            $table->index('document_group_id');
        });
    }

    public function down()
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['document_group_id', 'version', 'is_current', 'disk']);
        });
    }
};
