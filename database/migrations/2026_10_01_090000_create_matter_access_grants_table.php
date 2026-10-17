<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** SEC-10: explicit, revocable, optionally time-boxed access to one matter. Revocation is a soft delete (audited). */
    public function up()
    {
        Schema::create('matter_access_grants', function (Blueprint $table) {
            $table = Utils::createDefaultTableColumns($table);
            $table->foreignId('case_id')->constrained('cases');
            $table->foreignId('user_id')->constrained('users');
            $table->string('reason', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->index(['user_id', 'case_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('matter_access_grants');
    }
};
