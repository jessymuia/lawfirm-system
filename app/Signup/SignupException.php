<?php

namespace App\Signup;

/** PLAT-13: a signup the person can fix (a taken name, an expired link). The message is shown to them. */
class SignupException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $field = 'signup', public readonly int $status = 422)
    {
        parent::__construct($message);
    }
}
