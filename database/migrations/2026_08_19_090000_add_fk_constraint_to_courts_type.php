<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DATA-1: courts.type has no FK constraint to court_types, allowing
     * dangling values. Before adding the constraint we repoint any orphaned
     * rows to a fallback "Unclassified" court type so the migration never
     * fails on existing production data.
     */
    public function up()
    {
        $fallbackId = DB::table('court_types')->where('name', 'Unclassified')->value('id');

        if (! $fallbackId) {
            $fallbackId = DB::table('court_types')->insertGetId([
                'name' => 'Unclassified',
                'status' => 1,
                'archive' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $orphanCount = DB::table('courts')
            ->whereNotIn('type', DB::table('court_types')->select('id'))
            ->count();

        if ($orphanCount > 0) {
            DB::table('courts')
                ->whereNotIn('type', DB::table('court_types')->select('id'))
                ->update(['type' => $fallbackId]);
        }

        Schema::table('courts', function (Blueprint $table) {
            $table->foreign('type')->references('id')->on('court_types');
        });
    }

    public function down()
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->dropForeign(['type']);
        });
    }
};
