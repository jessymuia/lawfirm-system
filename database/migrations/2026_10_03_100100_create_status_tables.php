<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLAT-12: probe history and incidents for single-tenant installs. In multi-tenant mode the control-plane copy of these tables is used, so this migration does nothing there. */
return new class extends Migration
{
    public function up(): void
    {
        if (config('tenancy.mode') !== 'single') {
            return;   // tenant databases do not carry platform status data
        }
        $s = Schema::connection(config('database.default'));
        $s->create('health_probes', function (Blueprint $t) {
            $t->id();
            $t->string('component', 32);
            $t->string('status', 12);                                  // operational | degraded | down | unknown
            $t->unsignedInteger('latency_ms')->default(0);
            $t->string('detail', 120)->nullable();                     // fixed phrases only, never exception text
            $t->timestamp('probed_at');
            $t->index(['component', 'probed_at']);
            $t->index('probed_at');
        });
        $s->create('status_incidents', function (Blueprint $t) {
            $t->id();
            $t->string('title', 160);
            $t->string('impact', 16);                                  // none | minor | major | maintenance
            $t->string('status', 16);                                  // scheduled | investigating | identified | monitoring | resolved
            $t->json('components')->nullable();                        // empty = everything
            $t->timestamp('started_at');
            $t->timestamp('ends_at')->nullable();                      // planned end (maintenance)
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index('started_at');
        });
        $s->create('status_incident_updates', function (Blueprint $t) {
            $t->id();
            $t->foreignId('incident_id')->constrained('status_incidents')->cascadeOnDelete();
            $t->string('status', 16);
            $t->text('message');
            $t->unsignedBigInteger('staff_id')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        $s = Schema::connection(config('database.default'));
        $s->dropIfExists('status_incident_updates');
        $s->dropIfExists('status_incidents');
        $s->dropIfExists('health_probes');
    }
};
