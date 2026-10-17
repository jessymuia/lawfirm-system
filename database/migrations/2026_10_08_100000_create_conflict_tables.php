<?php

use App\AppUtils\Utils;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUN-9: conflict-of-interest checks at intake.
 *  - matter_parties: everyone a matter is for, against or about (adverse party, co-party, related party, opposing
 *    counsel), each with a normalised name for matching. Existing cases.opposing_party values are copied in by the
 *    queued App\Jobs\BackfillMatterParties (DATA-13: backfills are jobs, not migrations); opposing_party stays and is
 *    kept in step (dual write) until a later release reads parties only.
 *  - conflict_checks: the record of every check (what was searched, what was found, and who decided to proceed and
 *    why), which professional rules expect a firm to be able to produce.
 *  - firm_settings: small firm-wide switches (here: whether a cleared check is required before opening a client or matter).
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_parties', function (Blueprint $table) {
            Utils::createDefaultTableColumns($table);
            $table->foreignId('case_id')->constrained('cases')->cascadeOnDelete();   // a matter's parties go with it
            $table->string('role', 24);                    // adverse | co_party | related | opposing_counsel
            $table->string('name');
            $table->string('name_normalized')->index();
            $table->string('identifier', 64)->nullable();  // national ID / company registration number
            $table->string('identifier_normalized', 64)->nullable()->index();
            $table->string('notes', 500)->nullable();
            $table->boolean('from_opposing_party')->default(false);   // mirrors cases.opposing_party (dual write)
            $table->index(['case_id', 'role']);
        });

        Schema::create('conflict_checks', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 16);                 // client | matter | search
            $table->json('query');                         // names (with roles), phone, identifier searched
            $table->json('hits');
            $table->string('outcome', 16);                 // clear | potential
            $table->string('decision', 16)->nullable();    // proceed | declined
            $table->string('decision_note', 1000)->nullable();
            $table->unsignedBigInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('subject_type', 16)->nullable(); // client | matter, once the record it cleared is created
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['subject_type', 'subject_id']);
            $table->index('created_at');
            $table->foreign('created_by')->references('id')->on('users');
            $table->foreign('decided_by')->references('id')->on('users');
        });

        Schema::create('firm_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_settings');
        Schema::dropIfExists('conflict_checks');
        Schema::dropIfExists('matter_parties');
    }
};
