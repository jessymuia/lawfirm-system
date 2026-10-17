<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-14: every restore of a firm and every restore drill, with what it restored from, to which moment, how long it
 * took and whether the database and its files came back as a consistent pair (docs/ops/FIRM_RESTORE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('tenant_restores', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('kind', 12);                         // drill | restore | switch
            $t->string('snapshot', 40);                     // the snapshot restored from (its timestamp id)
            $t->timestamp('target_time')->nullable();       // point in time replayed to (null: the snapshot itself)
            $t->string('restored_database', 128)->nullable();
            $t->string('previous_database', 128)->nullable();   // kept after a switch, for rolling back
            $t->string('outcome', 12);                      // passed | failed | switched
            $t->unsignedInteger('duration_ms')->default(0);
            $t->text('report');                             // JSON
            $t->string('run_by', 191)->nullable();
            $t->timestamp('started_at');
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['tenant_id', 'kind', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('tenant_restores');
    }
};
