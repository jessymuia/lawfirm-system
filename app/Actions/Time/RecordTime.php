<?php

namespace App\Actions\Time;

use App\Actions\ActionException;
use App\Actions\Concerns\Stamps;
use App\Models\TimeEntry;
use App\Models\User;

/** ENG-9: log, correct and remove time. Time already billed is part of an invoice and cannot be changed. */
class RecordTime
{
    use Stamps;

    public const FIELDS = ['case_id', 'description', 'date', 'hours', 'hourly_rate', 'billable', 'utbms_task_code', 'utbms_activity_code'];

    /** @param array<string, mixed> $data date as Y-m-d */
    public function log(array $data, User $by): TimeEntry
    {
        $entry = new TimeEntry;
        $entry->fill(['user_id' => $by->getKey(), 'billable' => true] + array_intersect_key($data, array_flip(self::FIELDS)));
        $entry->forceFill(['created_by' => $by->getKey()])->save();

        return $entry;
    }

    /** @param array<string, mixed> $data only the fields to change */
    public function correct(TimeEntry $entry, array $data, ?User $actor): TimeEntry
    {
        if ($entry->billed) {
            throw new ActionException('This time entry has already been billed and cannot be edited');
        }
        $entry->fill(array_intersect_key($data, array_flip(self::FIELDS)));
        $this->stamp($entry, $actor)->save();

        return $entry;
    }

    public function remove(TimeEntry $entry, ?User $actor): void
    {
        if ($entry->billed) {
            throw new ActionException('This time entry has already been billed and cannot be deleted');
        }
        $this->softDelete($entry, $actor);
    }
}
