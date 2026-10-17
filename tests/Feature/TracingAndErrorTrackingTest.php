<?php

namespace Tests\Feature;

use App\Entitlements\EntitlementException;
use App\Observability\ErrorReporter;
use App\Observability\Instrumentation;
use App\Observability\Tracer;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-24: OTLP traces and Sentry-protocol error reports, both tagged with the firm. */
class TracingAndErrorTrackingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('api')->get('/api/v1/__trace/{id}', function () {
            DB::table('users')->where('id', 'client-secret-value')->count();

            return response()->json(['ok' => true]);
        });
        Route::middleware('api')->get('/api/v1/__boom', fn () => throw new \RuntimeException('it broke'));
    }

    private function tracing(): void
    {
        config(['observability.tracing.endpoint' => 'http://collector.test:4318', 'observability.tracing.headers' => 'x-api-key=k%3D1,x-team=law']);
        Instrumentation::register();
    }

    /** @return list<array<string, mixed>> */
    private function spansSent(): array
    {
        $spans = [];
        foreach (Http::recorded() as [$request]) {
            if (str_ends_with($request->url(), '/v1/traces')) {
                array_push($spans, ...$request->data()['resourceSpans'][0]['scopeSpans'][0]['spans']);
            }
        }

        return $spans;
    }

    /** @param  array<string, mixed>  $span */
    private static function attr(array $span, string $key): mixed
    {
        foreach ($span['attributes'] as $a) {
            if ($a['key'] === $key) {
                return array_values($a['value'])[0];
            }
        }

        return null;
    }

    public function test_off_by_default_nothing_is_sent(): void
    {
        Http::fake();
        $this->getJson('/api/v1/__trace/1')->assertOk();
        try {
            $this->getJson('/api/v1/__boom');
        } catch (\Throwable) {
        }
        Http::assertNothingSent();
        $this->assertFalse(app(Tracer::class)->enabled());
        $this->assertFalse(app(ErrorReporter::class)->enabled());
    }

    public function test_a_request_is_one_trace_tagged_with_its_firm_with_database_spans_and_no_bound_values(): void
    {
        Http::fake(['collector.test/*' => Http::response([], 200)]);
        $this->tracing();

        $this->getJson('/api/v1/__trace/7', ['X-Request-Id' => 'req-12345678'])->assertOk();

        Http::assertSent(fn (SentRequest $r) => $r->url() === 'http://collector.test:4318/v1/traces'
            && $r->header('x-api-key') === ['k=1'] && $r->header('x-team') === ['law']);
        $spans = $this->spansSent();
        $root = collect($spans)->firstWhere('kind', 2);
        $this->assertSame('GET /api/v1/__trace/{id}', $root['name']);
        $this->assertSame(app(TenantContext::class)->key(), self::attr($root, 'tenant'));
        $this->assertSame('req-12345678', self::attr($root, 'request_id'));
        $this->assertSame('200', self::attr($root, 'http.response.status_code'));
        $this->assertSame('/api/v1/__trace/{id}', self::attr($root, 'http.route'));
        $this->assertSame(1, $root['status']['code']);

        $db = collect($spans)->first(fn ($s) => $s['kind'] === 3 && str_contains((string) self::attr($s, 'db.query.text'), 'users'));
        $this->assertNotNull($db, 'the query is a child span');
        $this->assertSame($root['spanId'], $db['parentSpanId']);
        $this->assertSame($root['traceId'], $db['traceId']);
        $this->assertStringNotContainsString('client-secret-value', json_encode($spans), 'bound values never leave');
    }

    public function test_the_callers_trace_is_continued_and_a_server_error_marks_the_span(): void
    {
        Http::fake(['collector.test/*' => Http::response([], 200)]);
        $this->tracing();

        $this->get('/api/v1/__boom', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertStatus(500);

        $root = collect($this->spansSent())->firstWhere('kind', 2);
        $this->assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $root['traceId']);
        $this->assertSame('00f067aa0ba902b7', $root['parentSpanId']);
        $this->assertSame(2, $root['status']['code']);
    }

    public function test_sampling_keeps_none_at_zero_unless_the_caller_sampled(): void
    {
        Http::fake(['collector.test/*' => Http::response([], 200)]);
        $this->tracing();
        config(['observability.tracing.sample_ratio' => 0.0]);

        $this->getJson('/api/v1/__trace/1')->assertOk();
        $this->assertSame([], $this->spansSent());

        $this->getJson('/api/v1/__trace/1', ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'])->assertOk();
        $this->assertNotSame([], $this->spansSent());
        $this->assertNull(Tracer::parseTraceparent('00-'.str_repeat('0', 32).'-00f067aa0ba902b7-01'), 'an all-zero trace id is invalid');
        $this->assertNull(Tracer::parseTraceparent('garbage'));
    }

    public function test_a_queued_job_continues_the_trace_that_dispatched_it(): void
    {
        Http::fake(['collector.test/*' => Http::response([], 200)]);
        $this->tracing();
        $tracer = app(Tracer::class);

        $root = $tracer->begin('HTTP POST', 2);
        Context::addHidden('traceparent', $tracer->traceparent());
        // inline (sync) job inside the request: a child span
        $tracer->startJob('App\\Jobs\\Inline');
        $tracer->endJob();
        $tracer->finish();
        $spans = $this->spansSent();
        $this->assertSame('job App\\Jobs\\Inline', $spans[1]['name']);
        $this->assertSame($root->spanId, $spans[1]['parentSpanId']);

        // on a worker: its own trace, same trace id, parented to the request; a failure marks it
        Http::fake(['collector.test/*' => Http::response([], 200)]);
        $tracer->startJob('App\\Jobs\\Worker');
        $tracer->endJob('RuntimeException: nope');
        $job = collect($this->spansSent())->firstWhere('name', 'job App\\Jobs\\Worker');
        $this->assertSame($root->traceId, $job['traceId']);
        $this->assertSame($root->spanId, $job['parentSpanId']);
        $this->assertSame(['code' => 2, 'message' => 'RuntimeException: nope'], $job['status']);
    }

    public function test_an_unreachable_collector_never_breaks_the_request(): void
    {
        Http::fake(['collector.test/*' => fn () => throw new ConnectionException('down')]);
        $this->tracing();

        $this->getJson('/api/v1/__trace/1')->assertOk();
    }

    public function test_errors_go_to_the_tracker_tagged_and_grouped_per_firm_without_request_data(): void
    {
        Http::fake(['errors.test/*' => Http::response(['id' => 'x'], 200)]);
        config(['observability.errors.dsn' => 'https://pubkey@errors.test/sub/42', 'observability.errors.release' => '1.2.3']);

        $this->getJson('/api/v1/__boom?token=query-secret', ['X-Request-Id' => 'req-abcdefgh', 'Authorization' => 'Bearer header-secret'])->assertStatus(500);

        Http::assertSentCount(1);
        Http::assertSent(function (SentRequest $r) {
            $this->assertSame('https://errors.test/sub/api/42/envelope/', $r->url());
            $this->assertStringContainsString('sentry_key=pubkey', $r->header('X-Sentry-Auth')[0]);
            [$header, $item, $event] = array_map(fn ($l) => json_decode($l, true), explode("\n", trim($r->body())));
            $this->assertSame($event['event_id'], $header['event_id']);
            $this->assertSame('event', $item['type']);
            $tenant = app(TenantContext::class)->key();
            $this->assertSame($tenant, $event['tags']['tenant']);
            $this->assertSame('req-abcdefgh', $event['tags']['request_id']);
            $this->assertSame('/api/v1/__boom', $event['tags']['route']);
            $this->assertSame(['{{ default }}', "tenant:{$tenant}"], $event['fingerprint']);
            $this->assertSame('1.2.3', $event['release']);
            $exception = end($event['exception']['values']);
            $this->assertSame('RuntimeException', $exception['type']);
            $this->assertSame('it broke', $exception['value']);
            $this->assertNotEmpty($exception['stacktrace']['frames']);
            $this->assertStringNotContainsString('query-secret', $r->body());
            $this->assertStringNotContainsString('header-secret', $r->body());

            return true;
        });
    }

    public function test_one_issue_for_all_firms_when_per_firm_grouping_is_off_and_unreported_exceptions_stay_out(): void
    {
        Http::fake(['errors.test/*' => Http::response([], 200)]);
        config(['observability.errors.dsn' => 'http://pubkey@errors.test:9000/7', 'observability.errors.group_per_tenant' => false]);

        $event = app(ErrorReporter::class)->event(new \LogicException('x'));
        $this->assertArrayNotHasKey('fingerprint', $event);

        report(new EntitlementException('plan', 'feature', 'x'));   // a dontReport type
        Http::assertNothingSent();
        report(new \LogicException('y'));
        Http::assertSent(fn (SentRequest $r) => $r->url() === 'http://errors.test:9000/api/7/envelope/');
    }

    public function test_a_malformed_dsn_is_off_and_an_unreachable_tracker_is_harmless(): void
    {
        foreach (['not a dsn', 'https://errors.test/42', 'ftp://k@errors.test/1'] as $dsn) {
            config(['observability.errors.dsn' => $dsn]);
            $this->assertFalse(app(ErrorReporter::class)->enabled(), $dsn);
        }
        Http::fake(['errors.test/*' => fn () => throw new ConnectionException('down')]);
        config(['observability.errors.dsn' => 'https://k@errors.test/1']);
        app(ErrorReporter::class)->report(new \RuntimeException('z'));
        $this->assertTrue(true, 'no exception escaped');
    }
}
