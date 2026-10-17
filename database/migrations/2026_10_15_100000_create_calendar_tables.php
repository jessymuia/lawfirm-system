<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-16: the court diary and calendar sync.
 *  - hearings: time, length, courtroom, judicial officer and the judiciary e-filing reference (all optional).
 *  - calendar_feeds: secret iCalendar subscription links (only the token's SHA-256 is kept).
 *  - calendar_connections: a person's Google or Microsoft calendar (tokens encrypted at rest).
 *  - calendar_event_links: which external event mirrors which hearing or task, and what was last sent.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hearings', function (Blueprint $table) {
            $table->time('start_time')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('courtroom', 64)->nullable();
            $table->string('judicial_officer', 120)->nullable();
            $table->string('efiling_reference', 64)->nullable();
        });

        Schema::create('calendar_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('scope', 12);                       // mine | diary
            $table->string('token_hash', 64)->unique();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('calendar_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('provider', 16);                    // google | microsoft
            $table->string('account_email', 191)->nullable();
            $table->string('calendar_id', 191)->default('primary');
            $table->text('access_token');                      // encrypted
            $table->text('refresh_token')->nullable();         // encrypted
            $table->timestamp('expires_at')->nullable();
            $table->text('sync_token')->nullable();            // the provider's cursor for changes made on their side
            $table->string('state', 16)->default('active');    // active | error
            $table->string('error', 500)->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        Schema::create('calendar_event_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_connection_id')->constrained('calendar_connections')->cascadeOnDelete();
            $table->string('source_type', 12);                 // hearing | task
            $table->unsignedBigInteger('source_id');
            $table->string('external_id', 255);
            $table->string('synced_hash', 64)->nullable();     // what was last sent; a change re-sends
            $table->date('synced_date')->nullable();           // the date last sent, to recognise a move made in the calendar
            $table->timestamps();
            $table->unique(['calendar_connection_id', 'source_type', 'source_id'], 'calendar_link_source');
            $table->index(['calendar_connection_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_connections');
        Schema::dropIfExists('calendar_feeds');
        Schema::table('hearings', fn (Blueprint $t) => $t->dropColumn(['start_time', 'duration_minutes', 'courtroom', 'judicial_officer', 'efiling_reference']));
    }
};
