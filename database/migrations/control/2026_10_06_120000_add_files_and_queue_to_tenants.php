<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TEN-2: a firm's row says where ALL its data lives: database (cell + database), files (files_disk; keys under
 * tenants/<slug>/) and background work (queue). A cell may set defaults for firms placed on it (per-region buckets and
 * queues, TEN-15). Additive; existing firms are filled with what they use today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->string('files_disk', 64)->nullable();
            $table->string('queue', 64)->nullable();
        });
        Schema::connection('control')->table('cells', function (Blueprint $table) {
            $table->string('files_disk', 64)->nullable();
            $table->string('queue', 64)->nullable();
        });

        DB::connection('control')->table('tenants')->whereNull('files_disk')->update([
            'files_disk' => filled(config('filesystems.disks.s3.bucket')) ? 's3' : 'local',
            'queue' => (string) (config('queue.connections.'.config('queue.default').'.queue') ?: 'default'),
        ]);
    }

    public function down(): void
    {
        Schema::connection('control')->table('tenants', function (Blueprint $table) {
            $table->dropColumn(['files_disk', 'queue']);
        });
        Schema::connection('control')->table('cells', function (Blueprint $table) {
            $table->dropColumn(['files_disk', 'queue']);
        });
    }
};
