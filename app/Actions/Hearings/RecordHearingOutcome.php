<?php

namespace App\Actions\Hearings;

use App\Models\Hearing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ENG-9 (FUN-19): what happened at a hearing, and the next date if the court gave one: the next hearing is in the same
 * court and courtroom, before the same officer, unless told otherwise.
 */
class RecordHearingOutcome
{
    public function __construct(private ScheduleHearing $hearings) {}

    /** @param array{next_date?: ?string, next_time?: ?string, next_type?: ?int, next_notes?: ?string} $next */
    public function handle(Hearing $hearing, string $outcome, array $next, ?User $actor): ?Hearing
    {
        return DB::transaction(function () use ($hearing, $outcome, $next, $actor) {
            $this->hearings->change($hearing, ['outcome' => $outcome], $actor);
            if (empty($next['next_date'])) {
                return null;
            }

            return $this->hearings->schedule([
                'case_id' => $hearing->case_id, 'court_id' => $hearing->court_id, 'hearing_date' => $next['next_date'],
                'hearing_type' => $next['next_type'] ?? $hearing->hearing_type, 'start_time' => $next['next_time'] ?? null,
                'courtroom' => $hearing->courtroom, 'judicial_officer' => $hearing->judicial_officer, 'efiling_reference' => $hearing->efiling_reference,
                'notes' => $next['next_notes'] ?? 'Next date given at the hearing of '.substr((string) $hearing->hearing_date, 0, 10).'.',
            ], $actor);
        });
    }
}
