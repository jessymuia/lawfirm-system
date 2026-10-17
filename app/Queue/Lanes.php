<?php

namespace App\Queue;

/**
 * ENG-20: queue lanes. Every firm's work goes to its cell's lane (TEN-2, `default` unless the cell names one), split in
 * two:
 *  - the lane itself for interactive work people wait on (SMS, mail, calendar sync, webhooks);
 *  - `<lane>-bulk` (`bulk` for the default lane) for heavy work nobody waits on (exports, backfills, reading documents).
 * Workers serve the interactive lane first (`queue:work --queue=default,bulk`) or bulk work gets its own workers, so a
 * firm's export never sits in front of another firm's hearing reminder.
 */
class Lanes
{
    /** The interactive lane of the firm active now. */
    public static function interactive(): string
    {
        return (string) (config('queue.connections.'.config('queue.default').'.queue') ?: 'default');
    }

    /** The bulk lane next to it. */
    public static function bulk(): string
    {
        $lane = self::interactive();

        return $lane === 'default' ? 'bulk' : $lane.'-bulk';
    }
}
