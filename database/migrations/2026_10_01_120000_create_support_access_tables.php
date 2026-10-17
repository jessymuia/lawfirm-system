<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * PLAT-8: the firm's own record of platform-support access. Lives in the TENANT database so the firm (and an export of its
     * data) always has proof of who from the platform team accessed it, why, and when.
     */
    public function up()
    {
        Schema::create('support_access_sessions', function (Blueprint $table) {
            // Not Utils::createDefaultTableColumns(): its tinyint `status` would collide with this table's own lifecycle `status`.
            $table->id();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unsignedBigInteger('platform_staff_id');
            $table->string('staff_name');
            $table->string('staff_email');
            $table->string('reason', 1000);
            $table->string('mode', 16)->default('read_only');             // read_only | full
            $table->unsignedSmallInteger('requested_minutes');
            // pending -> approved -> active -> ended | expired | revoked ; pending -> denied | expired
            $table->string('status', 16)->default('pending');
            $table->timestamp('requested_at')->useCurrent();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('ended_by', 16)->nullable();                    // staff | firm | expiry
            $table->unsignedBigInteger('user_id')->nullable();             // the ephemeral support principal
            $table->index(['status', 'expires_at']);
            $table->index('user_id');
        });

        Schema::create('support_access_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('session_id');
            $table->string('method', 10);
            $table->string('path', 255);
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('session_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_staff_id')->nullable()->index();   // set only on ephemeral support principals
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('platform_staff_id');
        });
        Schema::dropIfExists('support_access_log');
        Schema::dropIfExists('support_access_sessions');
    }
};
