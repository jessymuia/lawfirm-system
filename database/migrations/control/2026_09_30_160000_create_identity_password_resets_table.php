<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** TEN-5: password-reset tokens belong to the identity, not to any one firm. */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection('control')->create('identity_password_resets', function (Blueprint $table) {
            $table->string('email')->index();
            $table->string('token');           // hashed
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('identity_password_resets');
    }
};
