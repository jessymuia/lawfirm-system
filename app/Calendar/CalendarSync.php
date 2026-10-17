<?php

namespace App\Calendar;

use App\Tenancy\Keys\FirmCrypt;
use App\Calendar\Providers\CalendarAuthException;
use App\Calendar\Providers\CalendarProvider;
use App\Calendar\Providers\GoogleCalendar;
use App\Calendar\Providers\MicrosoftCalendar;
use App\Models\User;
use App\Security\SecurityPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FUN-16: keeps a person's Google or Microsoft calendar in step with their hearings and tasks.
 *
 * Each run first reads what changed in the calendar since last time, then sends what changed here:
 *  - A task moved to another day in the calendar takes that day as its new due date (two-way for tasks).
 *  - A hearing moved or deleted in the calendar is put back: the court sets the date, so the system stays the authority.
 *  - New, changed, and no-longer-relevant hearings and tasks are created, updated and removed in the calendar.
 * Runs are idempotent and serialised per connection; a refused token marks the connection for reconnection.
 */
class CalendarSync
{
    public function __construct(private CalendarItems $items) {}

    public function provider(string $name): CalendarProvider
    {
        return match ($name) {
            'google' => app(GoogleCalendar::class),
            'microsoft' => app(MicrosoftCalendar::class),
            default => throw new \InvalidArgumentException("Unknown calendar provider {$name}."),
        };
    }

    /** @return array{created: int, updated: int, removed: int, tasks_moved: int, hearings_restored: int}|null null when another run holds the connection */
    public function run(int $connectionId): ?array
    {
        $lock = Cache::lock('calendar-sync:'.app(TenantContext::class)->key().':'.$connectionId, 300);
        if (! $lock->get()) {
            return null;
        }
        try {
            $c = DB::table('calendar_connections')->where('id', $connectionId)->first();
            if (! $c) {
                return null;
            }
            $user = User::find($c->user_id);
            if (! $user) {
                return null;
            }

            try {
                $report = $this->sync($c, $user);
                DB::table('calendar_connections')->where('id', $c->id)->update(['state' => 'active', 'error' => null, 'last_synced_at' => now(), 'updated_at' => now()]);

                return $report;
            } catch (CalendarAuthException $e) {
                DB::table('calendar_connections')->where('id', $c->id)->update(['state' => 'error', 'error' => Str::limit($e->getMessage(), 480), 'updated_at' => now()]);
            } catch (\Throwable $e) {
                report($e);
                DB::table('calendar_connections')->where('id', $c->id)->update(['error' => Str::limit($e->getMessage(), 480), 'updated_at' => now()]);
            }

            return null;
        } finally {
            $lock->release();
        }
    }

    private function sync(object $c, User $user): array
    {
        $provider = $this->provider($c->provider);
        $token = $this->accessToken($c, $provider);
        $report = ['created' => 0, 'updated' => 0, 'removed' => 0, 'tasks_moved' => 0, 'hearings_restored' => 0];

        // 1. what changed over there
        $pulled = $provider->changes($token, $c->calendar_id, $c->sync_token);
        $links = DB::table('calendar_event_links')->where('calendar_connection_id', $c->id)->get()->keyBy('external_id');
        foreach ($pulled['changes'] as $ch) {
            $link = $links->get($ch['id']);
            if (! $link) {
                continue;   // not one of ours
            }
            if ($link->source_type === 'task' && ! $ch['cancelled'] && $ch['date'] && $ch['date'] !== $link->synced_date) {
                $moved = DB::table('tasks')->where('id', $link->source_id)->where('assigned_to', $user->id)->whereNull('deleted_at')
                    ->where('task_status', '!=', 'completed')->update(['due_date' => $ch['date'], 'updated_at' => now(), 'updated_by' => $user->id]);
                if ($moved) {
                    $report['tasks_moved']++;
                    DB::table('calendar_event_links')->where('id', $link->id)->update(['synced_date' => $ch['date'], 'synced_hash' => null, 'updated_at' => now()]);
                    SecurityPolicy::audit('calendar_task_moved', 'task', (int) $link->source_id, ['due_date' => $link->synced_date], ['due_date' => $ch['date'], 'via' => $c->provider]);
                }
            } elseif ($link->source_type === 'hearing' && ($ch['cancelled'] || ($ch['date'] && $ch['date'] !== $link->synced_date))) {
                // the court sets the date: send ours again
                DB::table('calendar_event_links')->where('id', $link->id)->update(['synced_hash' => null, 'updated_at' => now()]);
                $report['hearings_restored']++;
            } elseif ($ch['cancelled']) {
                DB::table('calendar_event_links')->where('id', $link->id)->update(['synced_hash' => null, 'updated_at' => now()]);
            }
        }

        // 2. what changed here
        $want = collect($this->items->for($user, 'mine'))->keyBy(fn ($i) => $i['type'].':'.$i['id']);
        $have = DB::table('calendar_event_links')->where('calendar_connection_id', $c->id)->get()->keyBy(fn ($l) => $l->source_type.':'.$l->source_id);
        foreach ($want as $key => $item) {
            $hash = CalendarItems::hash($item);
            $link = $have->get($key);
            if ($link && $link->synced_hash === $hash) {
                continue;
            }
            $id = $provider->upsert($token, $c->calendar_id, $item, $link?->external_id);
            DB::table('calendar_event_links')->updateOrInsert(
                ['calendar_connection_id' => $c->id, 'source_type' => $item['type'], 'source_id' => $item['id']],
                ['external_id' => $id, 'synced_hash' => $hash, 'synced_date' => $item['date'], 'updated_at' => now(), 'created_at' => $link->created_at ?? now()],
            );
            $link ? $report['updated']++ : $report['created']++;
        }
        foreach ($have as $key => $link) {
            if (! $want->has($key)) {
                $provider->delete($token, $c->calendar_id, $link->external_id);
                DB::table('calendar_event_links')->where('id', $link->id)->delete();
                $report['removed']++;
            }
        }

        DB::table('calendar_connections')->where('id', $c->id)->update(['sync_token' => $pulled['cursor'], 'updated_at' => now()]);

        return $report;
    }

    /** A valid access token, refreshed when it is about to expire. */
    private function accessToken(object $c, CalendarProvider $provider): string
    {
        if ($c->expires_at === null || now()->addMinutes(2)->lt($c->expires_at)) {
            return FirmCrypt::decryptString($c->access_token);
        }
        if (! $c->refresh_token) {
            throw new CalendarAuthException('The calendar connection has expired. Connect it again.');
        }
        $t = $provider->refresh(FirmCrypt::decryptString($c->refresh_token));
        DB::table('calendar_connections')->where('id', $c->id)->update(array_filter([
            'access_token' => FirmCrypt::encryptString($t['access_token']),
            'refresh_token' => $t['refresh_token'] ? FirmCrypt::encryptString($t['refresh_token']) : null,   // only when the provider rotated it
            'expires_at' => now()->addSeconds($t['expires_in']), 'updated_at' => now(),
        ]));

        return $t['access_token'];
    }
}
