<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-18: a firm's own structure. Offices (Nairobi, Mombasa…) and practice groups (Litigation, Conveyancing…) are
 * WITHIN one firm: a multi-office firm is one tenant, never several (that would force the cross-tenant queries the
 * architecture removed). People and matters each belong to at most one office and one practice group; a matter
 * defaults to its responsible lawyer's. Additive and nullable: existing people and matters simply have none yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $table) {
            Utils::createDefaultTableColumns($table);
            $table->string('name', 120);
            $table->string('code', 16)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('address', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('email', 191)->nullable();
            $table->boolean('is_head_office')->default(false);
        });

        Schema::create('practice_groups', function (Blueprint $table) {
            Utils::createDefaultTableColumns($table);
            $table->string('name', 120);
            $table->string('code', 16)->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('head_user_id')->nullable()->constrained('users');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->constrained('offices');
            $table->foreignId('practice_group_id')->nullable()->constrained('practice_groups');
        });

        Schema::table('cases', function (Blueprint $table) {
            $table->foreignId('office_id')->nullable()->constrained('offices');
            $table->foreignId('practice_group_id')->nullable()->constrained('practice_groups');
            $table->index(['office_id', 'practice_group_id']);
        });
    }

    public function down(): void
    {
        Schema::table('cases', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropForeign(['practice_group_id']);
            $table->dropIndex(['office_id', 'practice_group_id']);
            $table->dropColumn(['office_id', 'practice_group_id']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['office_id']);
            $table->dropForeign(['practice_group_id']);
            $table->dropColumn(['office_id', 'practice_group_id']);
        });
        Schema::dropIfExists('practice_groups');
        Schema::dropIfExists('offices');
    }
};
