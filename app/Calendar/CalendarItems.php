<?php

namespace App\Calendar;

use App\Matters\MatterAccess;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * FUN-16: what belongs on a person's calendar, in one shape for the iCalendar feed and the Google/Microsoft sync.
 *
 *  - "mine": hearings on matters the person is responsible for or works on (assigned, attorney), plus their open tasks.
 *  - "diary": the court diary, every hearing on a matter the person may open (matter access and ethical walls apply).
 *
 * Only a window around today is included (config calendar.window). Hearing notes and outcomes never leave the system.
 */
class CalendarItems
{
    /**
     * @return list<array{uid: string, type: string, id: int, title: string, description: string, location: ?string, date: string,
     *     start: ?CarbonImmutable, end: ?CarbonImmutable, updated: CarbonImmutable, url: string}>
     */
    public function for(User $user, string $scope = 'mine'): array
    {
        $from = now()->subDays((int) config('calendar.window.past_days'))->toDateString();
        $to = now()->addDays((int) config('calendar.window.future_days'))->toDateString();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'lawfirm';
        $base = rtrim((string) config('app.url'), '/');

        $cases = MatterAccess::visibleCaseIds($user);
        if ($scope === 'mine') {
            $id = $user->getKey();
            $cases->where(fn ($q) => $q->where('cases.assigned_to', $id)
                ->orWhereIn('cases.id', DB::table('attorneys')->select('case_id')->where('advocate_id', $id)->whereNull('deleted_at')));
        }
        $cases->whereNull('cases.deleted_at');

        $hearings = DB::table('hearings')
            ->join('cases', 'cases.id', '=', 'hearings.case_id')
            ->leftJoin('clients', 'clients.id', '=', 'cases.client_id')
            ->leftJoin('courts', 'courts.id', '=', 'hearings.court_id')
            ->leftJoin('hearing_types', 'hearing_types.id', '=', 'hearings.hearing_type')
            ->leftJoin('users', 'users.id', '=', 'cases.assigned_to')
            ->whereIn('hearings.case_id', $cases)->whereNull('hearings.deleted_at')
            ->whereBetween('hearings.hearing_date', [$from, $to])
            ->orderBy('hearings.hearing_date')
            ->get(['hearings.id', 'hearings.hearing_date', 'hearings.start_time', 'hearings.duration_minutes', 'hearings.courtroom',
                'hearings.judicial_officer', 'hearings.efiling_reference', 'hearings.updated_at', 'hearings.case_id', 'cases.case_number',
                'cases.description', 'clients.name as client', 'courts.name as court_name', 'courts.alias as court_alias',
                'hearing_types.name as type_name', 'hearing_types.alias as type_alias', 'users.name as lawyer']);

        $items = [];
        foreach ($hearings as $h) {
            [$start, $end] = $this->times($h->hearing_date, $h->start_time, $h->duration_minutes);
            $type = $h->type_alias ?: $h->type_name ?: 'Hearing';
            $court = $h->court_alias ?: $h->court_name;
            $items[] = [
                'uid' => "hearing-{$h->id}@{$host}", 'type' => 'hearing', 'id' => (int) $h->id,
                'title' => "{$type}: {$h->case_number}".($h->client ? " ({$h->client})" : ''),
                'description' => implode("\n", array_filter([
                    $h->description, $h->lawyer ? "Advocate: {$h->lawyer}" : null, $h->judicial_officer ? "Before: {$h->judicial_officer}" : null,
                    $h->efiling_reference ? "E-filing ref: {$h->efiling_reference}" : null, "{$base}/view-case?case={$h->case_id}#hearings",
                ])),
                'location' => implode(', ', array_filter([$h->courtroom, $court])) ?: null,
                'date' => (string) $h->hearing_date, 'start' => $start, 'end' => $end,
                'updated' => CarbonImmutable::parse($h->updated_at ?? now()), 'url' => "{$base}/view-case?case={$h->case_id}#hearings",
            ];
        }

        if ($scope === 'mine') {
            $tasks = DB::table('tasks')->where('assigned_to', $user->getKey())->whereNull('deleted_at')
                ->where('task_status', '!=', 'completed')->whereBetween('due_date', [$from, $to])->orderBy('due_date')
                ->get(['id', 'title', 'description', 'due_date', 'priority', 'updated_at']);
            foreach ($tasks as $t) {
                $items[] = [
                    'uid' => "task-{$t->id}@{$host}", 'type' => 'task', 'id' => (int) $t->id, 'title' => "Task: {$t->title}",
                    'description' => trim(($t->description ?? '')."\nPriority: {$t->priority}\n{$base}/tasks"), 'location' => null,
                    'date' => (string) $t->due_date, 'start' => null, 'end' => null,
                    'updated' => CarbonImmutable::parse($t->updated_at ?? now()), 'url' => "{$base}/tasks",
                ];
            }
        }

        return $items;
    }

    /** A hearing with a time is a timed event (default length from config); without one it is an all-day entry. */
    private function times(string $date, ?string $time, ?int $minutes): array
    {
        if (! $time) {
            return [null, null];
        }
        $start = CarbonImmutable::parse(substr($date, 0, 10).' '.$time, (string) config('calendar.timezone'));

        return [$start, $start->addMinutes($minutes ?: (int) config('calendar.default_hearing_minutes'))];
    }

    /** A fingerprint of what an item says, to send it again only when something changed. */
    public static function hash(array $item): string
    {
        return hash('sha256', json_encode([$item['title'], $item['description'], $item['location'], $item['date'],
            $item['start']?->toIso8601String(), $item['end']?->toIso8601String()]));
    }
}
