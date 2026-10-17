<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SEC-11: ethical walls. A screen bars one user from one matter (case_id) or from every matter of one client (client_id).
     * It is lifted, never deleted, so the history stays reportable. Attempts to reach walled material are logged separately.
     */
    public function up()
    {
        Schema::create('ethical_screens', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('user_id')->constrained('users');          // the person walled off
            $table->foreignId('case_id')->nullable()->constrained('cases');
            $table->foreignId('client_id')->nullable()->constrained('clients');
            $table->string('reason', 1000);
            $table->timestamp('lifted_at')->nullable();
            $table->unsignedBigInteger('lifted_by')->nullable();
            $table->string('lift_reason', 1000)->nullable();
            $table->foreign('lifted_by')->references('id')->on('users');
            $table->index(['user_id', 'lifted_at']);
        });

        Schema::create('ethical_screen_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('screen_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('case_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('surface', 32);                 // api | write | notification
            $table->string('method', 40)->nullable();   // HTTP verb, or the console command that triggered a notification
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
            $table->index('screen_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('ethical_screen_attempts');
        Schema::dropIfExists('ethical_screens');
    }
};
