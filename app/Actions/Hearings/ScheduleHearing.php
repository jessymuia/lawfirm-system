<?php

namespace App\Actions\Hearings;

use App\Actions\Concerns\Stamps;
use App\Models\Hearing;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** ENG-9: put a hearing in the diary, change it, take it out (and put it back). People's synced calendars follow (FUN-16, model events). */
class ScheduleHearing
{
    use Stamps;

    public const FIELDS = ['case_id', 'court_id', 'hearing_date', 'hearing_type', 'notes', 'outcome', 'start_time', 'duration_minutes',
        'courtroom', 'judicial_officer', 'efiling_reference'];

    /** @param array<string, mixed> $data hearing_date as Y-m-d, start_time as H:i */
    public function schedule(array $data, ?User $actor): Hearing
    {
        $hearing = new Hearing;
        $hearing->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $actor && $hearing->forceFill(['created_by' => $actor->getKey()]);
        $hearing->save();

        return $hearing;
    }

    /** @param array<string, mixed> $data only the fields to change */
    public function change(Hearing $hearing, array $data, ?User $actor): Hearing
    {
        $hearing->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $this->stamp($hearing, $actor)->save();

        return $hearing;
    }

    /** @param iterable<Hearing> $hearings */
    public function cancel(iterable $hearings, ?User $actor): void
    {
        DB::transaction(function () use ($hearings, $actor) {
            foreach ($hearings as $hearing) {
                $this->softDelete($hearing, $actor);
            }
        });
    }

    public function restore(Hearing $hearing): Hearing
    {
        $hearing->restore();
        $hearing->forceFill(['deleted_by' => null])->save();

        return $hearing;
    }
}
