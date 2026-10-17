<?php

namespace App\Jobs;

use App\Calendar\CalendarSync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** FUN-16: one calendar connection brought in step (see CalendarSync). Runs in the firm it was dispatched from (TEN-3). */
class SyncCalendarConnection implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;   // the next change or the 15-minute schedule tries again

    public int $timeout = 300;

    public function __construct(public int $connectionId) {}

    public function handle(CalendarSync $sync): void
    {
        $sync->run($this->connectionId);
    }

    /** Sync the calendars of these people (after one of their hearings or tasks changed). */
    public static function forUsers(array $userIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $ids || ! \Illuminate\Support\Facades\Schema::hasTable('calendar_connections')) {
            return;
        }
        foreach (\Illuminate\Support\Facades\DB::table('calendar_connections')->whereIn('user_id', $ids)->where('state', 'active')->pluck('id') as $id) {
            self::dispatch((int) $id)->afterCommit();
        }
    }
}
