<?php

namespace App\Entitlements;

use RuntimeException;

class EntitlementException extends RuntimeException
{
    public function __construct(public readonly string $kind, public readonly string $name, string $message)
    {
        parent::__construct($message);
    }

    public static function feature(string $feature): self
    {
        return new self('feature', $feature, "Your plan does not include '{$feature}'.");
    }

    public static function limit(string $limit, ?int $max): self
    {
        return new self('limit', $limit, "Your plan's {$limit} limit ({$max}) has been reached.");
    }
}
