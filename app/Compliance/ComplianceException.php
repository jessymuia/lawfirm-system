<?php

namespace App\Compliance;

use RuntimeException;

/** A refused compliance action with a message safe to show (rendered as 422, or the given status). */
class ComplianceException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
