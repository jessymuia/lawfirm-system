<?php

namespace App\Observability;

/** ENG-24: one timed operation of a trace, kept until the trace is exported. */
final class Span
{
    /** @var array<string, scalar> */
    public array $attributes = [];

    public ?int $endNanos = null;

    public bool $error = false;

    public string $statusMessage = '';

    /** @param  array<string, scalar|null>  $attributes */
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly ?string $parentId,
        public string $name,
        public readonly int $kind,   // OTLP: 1 internal, 2 server, 3 client, 4 producer, 5 consumer
        public readonly int $startNanos,
        array $attributes = [],
    ) {
        $this->set($attributes);
    }

    /** @param  array<string, scalar|null>  $attributes */
    public function set(array $attributes): self
    {
        foreach ($attributes as $k => $v) {
            if ($v !== null) {
                $this->attributes[$k] = $v;
            }
        }

        return $this;
    }

    public function end(?int $at = null): void
    {
        $this->endNanos ??= $at ?? Tracer::now();
    }

    public function fail(string $message): void
    {
        $this->error = true;
        $this->statusMessage = mb_substr($message, 0, 500);
    }
}
