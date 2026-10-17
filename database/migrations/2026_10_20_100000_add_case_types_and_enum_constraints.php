<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DATA-12:
 *  - case_types: the firm's list of matter types, replacing free text in cases.case_type; cases.case_type_id points at
 *    it. The types already in use are filled in by the queued BackfillCaseTypes (DATA-13: backfills are jobs). The
 *    text column stays (expand/contract), kept in step by the application.
 *  - tasks.priority: a free string since 2025; on MySQL/MariaDB/PostgreSQL it is constrained to its values again.
 *    The other enum columns are ENUMs already. Existing rows are checked first: an unknown value stops the migration
 *    with a count, so nothing is guessed.
 */
return new class extends Migration
{
    private const PRIORITIES = ['high', 'medium', 'low'];

    public function up(): void
    {
        Schema::create('case_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->timestamp('archived_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('case_type_id')->nullable()->constrained('case_types');
        });

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb', 'pgsql'], true)) {
            $bad = DB::table('tasks')->whereNotIn('priority', self::PRIORITIES)->count();
            if ($bad > 0) {
                throw new RuntimeException("{$bad} task(s) have a priority other than high, medium or low; fix them, then migrate again.");
            }
            DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('high', 'medium', 'low'))");
        }
    }

    public function down(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE tasks DROP CHECK tasks_priority_check');
        } elseif (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tasks DROP CONSTRAINT tasks_priority_check');
        }
        Schema::table('cases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('case_type_id');
        });
        Schema::dropIfExists('case_types');
    }
};
