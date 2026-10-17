<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DATA-2: hearings.hearing_type is a plain integer with no FK to
     * hearing_types. Repoint any orphaned rows to a fallback "Unclassified"
     * hearing type before adding the constraint.
     */
    public function up()
    {
        $fallbackId = DB::table('hearing_types')->where('name', 'Unclassified')->value('id');

        if (! $fallbackId) {
            $fallbackId = DB::table('hearing_types')->insertGetId([
                'name' => 'Unclassified',
                'status' => 1,
                'archive' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $orphanCount = DB::table('hearings')
            ->whereNotIn('hearing_type', DB::table('hearing_types')->select('id'))
            ->count();

        if ($orphanCount > 0) {
            DB::table('hearings')
                ->whereNotIn('hearing_type', DB::table('hearing_types')->select('id'))
                ->update(['hearing_type' => $fallbackId]);
        }

        Schema::table('hearings', function (Blueprint $table) {
            $table->unsignedBigInteger('hearing_type')->change();
            $table->foreign('hearing_type')->references('id')->on('hearing_types');
        });
    }

    public function down()
    {
        Schema::table('hearings', function (Blueprint $table) {
            $table->dropForeign(['hearing_type']);
        });
    }
};
