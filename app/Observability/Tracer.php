<?php

namespace App\Observability;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ENG-24: request and job traces with the firm on every span, exported as OTLP/HTTP JSON to any OpenTelemetry backend.
 *
 * One trace per request (TraceRequests) or queued job (registered in AppServiceProvider), continuing the caller's W3C
 * `traceparent` when it sends one. Spans live in memory and leave in one POST after the response has been sent; an
 * unreachable collector costs at most the configured timeout and is never an error for the user. Off (no endpoint) it
 * records nothing.
 */
class Tracer
{
    private ?Span $root = null;

    /** @var list<Span> */
    private array $spans = [];

    private bool $sampled = false;

    private int $dropped = 0;

    /** @var list<Span|string|null> one entry per job being worked, innermost last ('root' = the job owns the trace) */
    private array $jobs = [];

    public function enabled(): bool
    {
        return (bool) config('observability.tracing.endpoint');
    }

    public static function now(): int
    {
        return (int) (microtime(true) * 1e9);
    }

    /**
     * Start the trace of one unit of work. Returns null when tracing is off or this trace is not sampled.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function begin(string $name, int $kind, array $attributes = [], ?string $traceparent = null): ?Span
    {
        $this->reset();
        if (! $this->enabled()) {
            return null;
        }
        [$traceId, $parentId, $callerSampled] = self::parseTraceparent($traceparent) ?? [bin2hex(random_bytes(16)), null, null];
        $this->sampled = $callerSampled ?? (mt_rand() / mt_getrandmax()) < (float) config('observability.tracing.sample_ratio', 1.0);
        if (! $this->sampled) {
            return null;
        }
        $this->root = new Span($traceId, self::spanId(), $parentId, $name, $kind, self::now(), $attributes + $this->context());
        $this->spans[] = $this->root;

        return $this->root;
    }

    /** @param  array<string, scalar|null>  $attributes */
    public function child(string $name, int $kind = 1, array $attributes = [], ?int $start = null): ?Span
    {
        if (! $this->root) {
            return null;
        }
        if (count($this->spans) >= max(1, (int) config('observability.tracing.max_spans', 500))) {
            $this->dropped++;

            return null;
        }
        $span = new Span($this->root->traceId, self::spanId(), $this->root->spanId, $name, $kind, $start ?? self::now(), $attributes);
        $this->spans[] = $span;

        return $span;
    }

    /**
     * A queued job starts its own trace, continuing the one that dispatched it; a job run inline inside a traced
     * request or job (the sync driver) is a child span of it instead.
     *
     * @param  array<string, scalar|null>  $attributes
     */
    public function startJob(string $name, array $attributes = []): void
    {
        if ($this->root) {
            $this->jobs[] = $this->child("job {$name}", 5, $attributes);

            return;
        }
        $this->begin("job {$name}", 5, $attributes, Context::getHidden('traceparent'));
        $this->jobs[] = 'root';
    }

    public function endJob(?string $error = null): void
    {
        $top = array_pop($this->jobs);
        if ($top === 'root') {
            if ($error !== null) {
                $this->root?->fail($error);
            }
            $this->finish();
        } elseif ($top instanceof Span) {
            if ($error !== null) {
                $top->fail($error);
            }
            $top->end();
        }
    }

    public function root(): ?Span
    {
        return $this->root;
    }

    /** The W3C header to hand to a downstream call (or null when not tracing). */
    public function traceparent(): ?string
    {
        return $this->root ? "00-{$this->root->traceId}-{$this->root->spanId}-01" : null;
    }

    /** End the root span and send the trace. Never throws. */
    public function finish(): void
    {
        if (! $this->root) {
            return;
        }
        $this->root->end();
        $this->root->set($this->context() + ['spans.dropped' => $this->dropped ?: null]);
        $payload = $this->payload();
        $this->reset();

        try {
            Http::timeout((float) config('observability.tracing.timeout', 2.0))
                ->withHeaders(self::headers((string) config('observability.tracing.headers', '')))
                ->asJson()
                ->post(rtrim((string) config('observability.tracing.endpoint'), '/').'/v1/traces', $payload)
                ->throw();
        } catch (\Throwable $e) {
            Log::debug('Trace export failed: '.$e->getMessage());
        }
    }

    public function reset(): void
    {
        $this->root = null;
        $this->spans = [];
        $this->sampled = false;
        $this->dropped = 0;
    }

    /** @return array<string, mixed> OTLP ExportTraceServiceRequest (JSON encoding) */
    public function payload(): array
    {
        $resource = [
            'service.name' => (string) config('observability.tracing.service_name', 'lawfirm'),
            'service.version' => (string) config('app.version', 'dev'),
            'deployment.environment' => (string) config('app.env'),
        ];

        return ['resourceSpans' => [[
            'resource' => ['attributes' => self::attributes($resource)],
            'scopeSpans' => [[
                'scope' => ['name' => 'app'],
                'spans' => array_map(fn (Span $s) => array_filter([
                    'traceId' => $s->traceId,
                    'spanId' => $s->spanId,
                    'parentSpanId' => $s->parentId,
                    'name' => $s->name,
                    'kind' => $s->kind,
                    'startTimeUnixNano' => (string) $s->startNanos,
                    'endTimeUnixNano' => (string) ($s->endNanos ?? self::now()),
                    'attributes' => self::attributes($s->attributes),
                    'status' => $s->error ? ['code' => 2, 'message' => $s->statusMessage] : ['code' => 1],
                ], fn ($v) => $v !== null), $this->spans),
            ]],
        ]]];
    }

    /** @return array<string, string|int> the firm and request on every root span */
    private function context(): array
    {
        return array_filter([
            'tenant' => app(TenantContext::class)->key(),
            'request_id' => Context::get('request_id'),
            'enduser.id' => auth()->hasUser() ? (int) auth()->id() : null,
        ], fn ($v) => $v !== null);
    }

    /** @return array{0: string, 1: string, 2: bool}|null */
    public static function parseTraceparent(?string $header): ?array
    {
        if ($header && preg_match('/^00-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/', trim($header), $m)
            && $m[1] !== str_repeat('0', 32) && $m[2] !== str_repeat('0', 16)) {
            return [$m[1], $m[2], (hexdec($m[3]) & 1) === 1];
        }

        return null;
    }

    private static function spanId(): string
    {
        return bin2hex(random_bytes(8));
    }

    /** @return array<string, string> */
    private static function headers(string $spec): array
    {
        $headers = [];
        foreach (array_filter(array_map('trim', explode(',', $spec))) as $pair) {
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($k) !== '') {
                $headers[trim($k)] = rawurldecode(trim($v));
            }
        }

        return $headers;
    }

    /**
     * @param  array<string, scalar>  $values
     * @return list<array{key: string, value: array<string, scalar>}>
     */
    private static function attributes(array $values): array
    {
        $out = [];
        foreach ($values as $k => $v) {
            $out[] = ['key' => (string) $k, 'value' => match (true) {
                is_bool($v) => ['boolValue' => $v],
                is_int($v) => ['intValue' => (string) $v],
                is_float($v) => ['doubleValue' => $v],
                default => ['stringValue' => (string) $v],
            }];
        }

        return $out;
    }
}
