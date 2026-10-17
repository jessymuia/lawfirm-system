<?php

namespace App\Conflicts;

/** FUN-9: an intake the conflict rules refuse; the message is shown to the person. */
class ConflictException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly string $code_ = 'conflict')
    {
        parent::__construct($message);
    }
}
