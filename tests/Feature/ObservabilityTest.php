<?php

namespace Tests\Feature;

use App\Observability\UsageMetrics;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** ENG-24: request correlation, structured logs, tenant-tagged errors. */
class ObservabilityTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logs = new TestHandler;
        Log::swap(new \Illuminate\Log\LogManager($this->app));
        config(['logging.default' => 'probe', 'logging.channels.probe' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
        Log::channel('probe')->getLogger()->setHandlers([$this->logs]);

        Route::middleware('api')->get('/api/v1/__probe', function () {
            Log::channel('probe')->info('inside request');

            return response()->json(['ok' => true]);
        });
    }

    public function test_response_carries_a_generated_request_id(): void
    {
        $id = $this->getJson('/api/v1/__probe')->assertOk()->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public function test_a_well_formed_inbound_request_id_is_kept(): void
    {
        $this->getJson('/api/v1/__probe', ['X-Request-Id' => 'edge-abc-12345'])->assertHeader('X-Request-Id', 'edge-abc-12345');
    }

    public function test_a_hostile_inbound_request_id_is_replaced(): void
    {
        foreach (["bad id with spaces", "x\nInjected: line", str_repeat('a', 500), 'short'] as $bad) {
            $got = $this->getJson('/api/v1/__probe', ['X-Request-Id' => $bad])->headers->get('X-Request-Id');
            $this->assertNotSame($bad, $got);
            $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $got);
        }
    }

    public function test_log_lines_in_a_request_carry_request_id_and_tenant(): void
    {
        $this->getJson('/api/v1/__probe', ['X-Request-Id' => 'trace-me-0001'])->assertOk();

        $record = collect($this->logs->getRecords())->first(fn ($r) => $r->message === 'inside request');
        $this->assertNotNull($record, 'probe log line missing');
        $this->assertSame('trace-me-0001', $record->extra['request_id'] ?? null);
        $this->assertSame(app(TenantContext::class)->key(), $record->extra['tenant'] ?? null);
    }

    public function test_authenticated_user_id_is_on_the_log_line(): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)->getJson('/api/v1/__probe')->assertOk();

        $record = collect($this->logs->getRecords())->first(fn ($r) => $r->message === 'inside request');
        $this->assertSame($user->id, $record->extra['user_id'] ?? null);
    }

    public function test_context_travels_with_queued_jobs(): void
    {
        Context::add(['request_id' => 'job-trace-0001', 'tenant' => 'default']);

        DB::table('jobs')->delete();
        dispatch(new ObservabilityProbeJob)->onConnection('database');

        $payload = json_decode(DB::table('jobs')->latest('id')->value('payload'), true);
        $this->assertSame(serialize('job-trace-0001'), $payload['illuminate:log:context']['data']['request_id'] ?? null);
    }

    public function test_reported_exceptions_are_tagged_with_tenant_and_request_id(): void
    {
        Context::add('request_id', 'err-trace-0001');

        $context = (new \ReflectionMethod(\App\Exceptions\Handler::class, 'context'));
        $context->setAccessible(true);
        $data = $context->invoke(app(\App\Exceptions\Handler::class));

        $this->assertSame('err-trace-0001', $data['request_id']);
        $this->assertSame(app(TenantContext::class)->key(), $data['tenant']);
    }

    public function test_json_channel_writes_one_parseable_object_per_line_with_context(): void
    {
        $file = sys_get_temp_dir().'/json-'.uniqid().'.log';
        config(['logging.channels.jsontest' => array_merge(config('logging.channels.json'), ['driver' => 'single', 'path' => $file])]);
        Context::add(['request_id' => 'json-trace-0001', 'tenant' => 'firm-x']);

        Log::channel('jsontest')->warning('structured hello', ['case' => 7]);
        Log::channel('jsontest')->info('second line');

        $lines = array_values(array_filter(explode("\n", file_get_contents($file))));
        $this->assertCount(2, $lines);
        $first = json_decode($lines[0], true);
        $this->assertSame('structured hello', $first['message']);
        $this->assertSame(['case' => 7], $first['context']);
        $this->assertSame('json-trace-0001', $first['extra']['request_id']);
        $this->assertSame('firm-x', $first['extra']['tenant']);
        unlink($file);
    }
}

class ObservabilityProbeJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable;
    use \Illuminate\Foundation\Bus\Dispatchable;

    public function handle(): void
    {
    }
}
