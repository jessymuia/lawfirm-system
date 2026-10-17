<?php

namespace App\Webhooks;

use RuntimeException;

class WebhookException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}
