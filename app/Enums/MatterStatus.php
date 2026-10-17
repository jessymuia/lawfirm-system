<?php

namespace App\Enums;

/** DATA-12: where a matter is in its life (cases.lifecycle_status), and where it may go next. */
enum MatterStatus: string
{
    use EnumValues;

    case Open = 'open';
    case Appeal = 'appeal';
    case Closed = 'closed';
    case Settled = 'settled';

    /** @return list<self> the statuses a matter may move to from this one */
    public function next(): array
    {
        return match ($this) {
            self::Open => [self::Closed, self::Appeal, self::Settled],
            self::Appeal => [self::Open, self::Settled, self::Closed],
            self::Closed => [self::Open],   // reopening a closed matter
            self::Settled => [],              // terminal
        };
    }
}
