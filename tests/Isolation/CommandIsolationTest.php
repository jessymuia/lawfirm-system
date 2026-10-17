<?php

namespace Tests\Isolation;

use App\Models\Client;
use App\Tenancy\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * TEN-10: console commands that fan out over every firm (scheduler, multi mode). Each must do a firm's work in that firm, report per
 * firm, never carry one firm's data or errors into the next, and leave the process fail-closed.
 */
class CommandIsolationTest extends IsolationTestCase
{
    private function cmd(string $cmd, array $args = []): string
    {
        Artisan::call($cmd, $args);

        return Artisan::output();
    }

    public function test_usage_is_counted_per_firm(): void
    {
        $out = $this->cmd('tenants:usage');

        $this->assertMatchesRegularExpression('/^a\s+users=2 matters=2/m', $out);
        $this->assertMatchesRegularExpression('/^b\s+users=2 matters=5/m', $out);
        $this->assertSame('tenant_unresolved', DB::getDefaultConnection());
    }

    public function test_usage_for_one_firm_does_not_touch_the_other(): void
    {
        $out = $this->cmd('tenants:usage', ['--tenant' => 'a']);

        $this->assertStringContainsString('matters=2', $out);
        $this->assertDoesNotMatchRegularExpression('/^b\s/m', $out);
    }

    public function test_webhook_events_reach_only_the_endpoints_of_the_firm_that_raised_them(): void
    {
        config(['sso.block_private_hosts' => false]);   // the sandbox has no DNS; the URL guard itself is tested in PublicApiTest
        Tenant::query()->update(['plan' => 'professional']);
        app(\App\Entitlements\Entitlements::class)->forget();
        $this->api('a', 'POST', '/integrations/webhooks', ['url' => 'https://hook-a.example.test/in', 'events' => ['*']])->assertCreated();
        $this->api('b', 'POST', '/integrations/webhooks', ['url' => 'https://hook-b.example.test/in', 'events' => ['*']])->assertCreated();
        // an event in each firm, carrying its canary
        foreach (['a', 'b'] as $slug) {
            $this->inFirm($slug, fn () => \App\Models\Client::factory()->create(['name' => $this->canary($slug).' hooked']));
        }
        $this->assertSame(1, $this->inFirm('a', fn () => DB::table('webhook_deliveries')->count()), 'the event must have been queued in A');
        $this->assertSame(1, $this->inFirm('b', fn () => DB::table('webhook_deliveries')->count()), 'and in B');
        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            $sent[$request->url()][] = $request->body();

            return Http::response('ok', 200);
        });

        $out = $this->cmd('webhooks:deliver');

        $this->assertNotEmpty($sent, 'the deliveries should have gone out: '.$out);
        $this->assertArrayHasKey('https://hook-a.example.test/in', $sent);
        $this->assertArrayHasKey('https://hook-b.example.test/in', $sent);
        $this->assertStringNotContainsString('CANARY-B', implode('', $sent['https://hook-a.example.test/in']), 'A\'s endpoint received B\'s event');
        $this->assertStringNotContainsString('CANARY-A', implode('', $sent['https://hook-b.example.test/in']), 'B\'s endpoint received A\'s event');
        $this->assertSame('tenant_unresolved', DB::getDefaultConnection());
    }

    public function test_one_broken_firm_does_not_stop_the_others_or_leak_into_them(): void
    {
        // B's database disappears; the fleet commands must still serve A and say which firm failed
        static::driver('mysql')
            ? $this->mysqlServer()->statement('DROP DATABASE `'.$this->firmDatabase('b').'`')
            : file_put_contents("{$this->dir}/b.sqlite", 'not a database');

        $out = $this->cmd('tenants:usage');

        $this->assertMatchesRegularExpression('/^a\s+users=2 matters=2/m', $out, 'a failure in B must not stop A');
        $this->assertStringNotContainsString('CANARY', $out);
        $this->assertSame('tenant_unresolved', DB::getDefaultConnection());
    }

    public function test_the_other_fleet_commands_leave_every_firm_unchanged_and_fail_closed(): void
    {
        $snapshot = fn () => [
            'a' => $this->inFirm('a', fn () => [Client::count(), DB::table('cases')->count(), DB::table('documents')->count()]),
            'b' => $this->inFirm('b', fn () => [Client::count(), DB::table('cases')->count(), DB::table('documents')->count()]),
        ];
        $before = $snapshot();

        foreach ([['health:probe'], ['support:expire'], ['retention:run'], ['billing:run']] as [$cmd]) {
            Artisan::call($cmd);
            $this->assertSame('tenant_unresolved', DB::getDefaultConnection(), "{$cmd} left the process pointed at a firm");
            $this->assertStringNotContainsString('CANARY', Artisan::output(), "{$cmd} printed a firm's data");
        }

        $this->assertSame($before, $snapshot(), 'a report-only command must not change any firm');
    }
}
