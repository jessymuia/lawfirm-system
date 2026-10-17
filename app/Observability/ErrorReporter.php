<?php

namespace App\Observability;

use App\Tenancy\TenantContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ENG-24: sends reported exceptions to an error tracker over the Sentry envelope protocol (Sentry, GlitchTip, Bugsink).
 *
 * Every event is tagged with the firm and request id and linked to its trace; with `group_per_tenant` the firm is part
 * of the fingerprint, so one bug hitting three firms is three issues, each with its own count and first/last seen.
 * Sent: exception class, message, stack frames (file, line, function; no arguments or local variables), route and
 * method. Never sent: request bodies, headers, cookies, query strings or any field values. Off without a DSN; a
 * tracker that cannot be reached is logged at debug and never breaks the request.
 */
class ErrorReporter
{
    public function enabled(): bool
    {
        return $this->dsn() !== null;
    }

    public function report(Throwable $e): void
    {
        $dsn = $this->dsn();
        if (! $dsn) {
            return;
        }
        $event = $this->event($e);
        $header = json_encode(['event_id' => $event['event_id'], 'sent_at' => gmdate('Y-m-d\TH:i:s\Z'), 'dsn' => $dsn['raw']]);
        $body = $header."\n".json_encode(['type' => 'event'])."\n".json_encode($event)."\n";

        try {
            Http::timeout((float) config('observability.errors.timeout', 2.0))
                ->withHeaders([
                    'Content-Type' => 'application/x-sentry-envelope',
                    'X-Sentry-Auth' => "Sentry sentry_version=7, sentry_client=lawfirm/1.0, sentry_key={$dsn['key']}",
                ])
                ->withBody($body, 'application/x-sentry-envelope')
                ->post($dsn['endpoint'])
                ->throw();
        } catch (Throwable $sendFailure) {
            Log::debug('Error report not delivered: '.$sendFailure->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function event(Throwable $e): array
    {
        $tenant = app(TenantContext::class)->key();
        $route = request()->route();   // only a request being served has one (a console run has an empty request)
        $request = $route instanceof Route ? request() : null;
        $trace = app(Tracer::class)->root();

        $exceptions = [];
        for ($x = $e, $depth = 0; $x && $depth < 5; $x = $x->getPrevious(), $depth++) {
            array_unshift($exceptions, [
                'type' => get_class($x),
                'value' => mb_substr($x->getMessage(), 0, 1000),
                'stacktrace' => ['frames' => $this->frames($x)],
            ]);
        }

        return array_filter([
            'event_id' => bin2hex(random_bytes(16)),
            'timestamp' => microtime(true),
            'platform' => 'php',
            'level' => 'error',
            'logger' => 'app',
            'server_name' => gethostname() ?: null,
            'environment' => (string) config('observability.errors.environment'),
            'release' => (string) (config('observability.errors.release') ?: config('app.version')),
            'exception' => ['values' => $exceptions],
            'tags' => array_filter([
                'tenant' => $tenant,
                'request_id' => Context::get('request_id'),
                'route' => $route instanceof Route ? '/'.ltrim($route->uri(), '/') : null,
                'http_method' => $request?->method(),
            ]),
            'user' => auth()->hasUser() ? ['id' => (string) auth()->id()] : null,
            'fingerprint' => config('observability.errors.group_per_tenant', true) ? ['{{ default }}', "tenant:{$tenant}"] : null,
            'contexts' => $trace ? ['trace' => ['trace_id' => $trace->traceId, 'span_id' => $trace->spanId]] : null,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return list<array<string, mixed>> oldest call first, as the protocol expects */
    private function frames(Throwable $e): array
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $frames = [['filename' => str_replace($base, '', $e->getFile()), 'lineno' => $e->getLine(), 'in_app' => ! str_contains($e->getFile(), '/vendor/')]];
        foreach (array_slice($e->getTrace(), 0, 50) as $f) {
            $file = (string) ($f['file'] ?? '[internal]');
            $frames[] = array_filter([
                'filename' => str_replace($base, '', $file),
                'lineno' => $f['line'] ?? null,
                'function' => isset($f['class']) ? $f['class'].($f['type'] ?? '::').$f['function'] : $f['function'],
                'in_app' => ! str_contains($file, '/vendor/') && $file !== '[internal]',
            ], fn ($v) => $v !== null);
        }

        return array_reverse($frames);
    }

    /** @return array{raw: string, key: string, endpoint: string}|null */
    private function dsn(): ?array
    {
        $raw = (string) config('observability.errors.dsn');
        $p = $raw !== '' ? parse_url($raw) : false;
        if (! $p || empty($p['user']) || empty($p['host']) || empty($p['path']) || ! in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            return null;
        }
        $segments = explode('/', trim($p['path'], '/'));
        $project = array_pop($segments);
        $prefix = $segments ? '/'.implode('/', $segments) : '';
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        return ['raw' => $raw, 'key' => $p['user'], 'endpoint' => "{$p['scheme']}://{$p['host']}{$port}{$prefix}/api/{$project}/envelope/"];
    }
}
