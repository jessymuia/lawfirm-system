<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-7: supporting indexes for global search. A leading-wildcard LIKE
     * ('%term%') can't use a plain B-tree index for the search itself, but
     * these still help the ORDER BY id DESC + LIMIT plan, and set up a
     * clean upgrade path to full-text search later without another
     * migration touching these same columns.
     *
     * cases.case_number is not indexed again here — DATA-5's migration
     * already added a composite unique index on (case_number, deleted_at),
     * whose leading column already accelerates lookups on case_number
     * alone.
     */
    public function up()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->index('name');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->index('title');
        });
    }

    public function down()
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['title']);
        });
    }
};
