<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * FUN-3: nothing schedules hearing-date reminders. `reminder_sent_at`
     * is the idempotency guard — the reminder command runs on a schedule
     * and must never send the same hearing's reminder twice.
     */
    public function up()
    {
        Schema::table('hearings', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('outcome');
        });
    }

    public function down()
    {
        Schema::table('hearings', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
