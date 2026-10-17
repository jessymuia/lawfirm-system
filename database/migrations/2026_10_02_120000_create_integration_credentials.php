<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SEC-15: a firm's own credentials for third-party services, encrypted at rest (expand-only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_credentials', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 40);
            $t->string('name', 60);
            $t->text('value');                       // encrypted with the app key
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_credentials');
    }
};
