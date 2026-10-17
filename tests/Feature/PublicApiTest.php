<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\EthicalScreen;
use App\Models\Hearing;
use App\Models\User;
use App\Webhooks\WebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** PLAT-11: scoped API keys, the read-only public API, and webhooks with retry/replay. */
class PublicApiTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private User $admin;

    private User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sso.block_private_hosts' => false, 'audit.console' => true]);
        Cache::flush();
        RateLimiter::clear('x');
        $this->admin = $this->userWithPermissions(['manage-api-access']);
        $this->reader = $this->userWithPermissions(['list-clients', 'list-cases', 'list-hearings']);
    }

    private function plan(string $plan): void
    {
        $this->usePlan($plan);
    }

    /** @return array{0:string,1:int} token and id */
    private function key(array $scopes = ['clients:read', 'cases:read', 'hearings:read'], ?User $as = null, array $extra = []): array
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/integrations/api-keys', ['name' => 'k', 'scopes' => $scopes, 'user_id' => ($as ?? $this->reader)->id] + $extra)->assertCreated();
        app('auth')->forgetGuards();

        return [$r->json('data.key'), $r->json('data.id')];
    }

    private function pub(string $path, ?string $token = null)
    {
        app('auth')->forgetGuards();
        $this->flushHeaders();   // withToken() persists on the test case: never let one call's key leak into the next

        return ($token ? $this->withToken($token) : $this)->getJson('/api/v1/public'.$path);
    }

    // ---- management ------------------------------------------------------------------------------------------------

    public function test_managing_keys_needs_the_permission_and_a_plan_with_api_access(): void
    {
        $nobody = $this->userWithPermissions(['list-clients']);
        $this->actingAs($nobody)->getJson('/api/v1/integrations/api-keys')->assertForbidden();
        $this->actingAs($nobody)->postJson('/api/v1/integrations/webhooks', ['url' => 'https://h.example.test', 'events' => ['*']])->assertForbidden();

        $this->plan('starter');
        $this->actingAs($this->admin)->getJson('/api/v1/integrations/api-keys')->assertForbidden()->assertJsonPath('code', 'entitlement_required');
        $this->actingAs($this->admin)->postJson('/api/v1/integrations/webhooks', ['url' => 'https://h.example.test', 'events' => ['*']])->assertForbidden();
    }

    public function test_the_key_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $r = $this->actingAs($this->admin)->postJson('/api/v1/integrations/api-keys', ['name' => 'CRM sync', 'scopes' => ['clients:read']])->assertCreated();
        $token = $r->json('data.key');
        $this->assertMatchesRegularExpression('/^lfk_[0-9a-f]{8}_[A-Za-z0-9]{32}$/', $token);
        $this->assertSame($this->admin->id, $r->json('data.user_id'));                     // defaults to the creator
        $secret = substr($token, 13);
        $row = DB::table('api_keys')->first();
        $this->assertSame(hash('sha256', $secret), $row->key_hash);
        $this->assertStringNotContainsString($secret, json_encode($row));

        $list = $this->actingAs($this->admin)->getJson('/api/v1/integrations/api-keys')->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertStringNotContainsString('key_hash', $list->getContent());
        $this->assertSame('lfk_'.$row->prefix, $list->json('data.0.prefix'));

        $audit = DB::table('audits')->where('event', 'api_key_created')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString($secret, json_encode($audit));
    }

    public function test_bad_key_requests_are_refused(): void
    {
        $base = ['name' => 'k', 'scopes' => ['clients:read']];
        foreach ([['scopes' => []], ['scopes' => ['cases:write']], ['name' => ''], ['rate_limit' => 0], ['rate_limit' => 999999], ['expires_at' => '2001-01-01'], ['user_id' => 99999]] as $bad) {
            $this->actingAs($this->admin)->postJson('/api/v1/integrations/api-keys', $bad + $base)->assertStatus(422);
        }
        $principal = User::factory()->create(['platform_staff_id' => 5]);
        $this->actingAs($this->admin)->postJson('/api/v1/integrations/api-keys', ['user_id' => $principal->id] + $base)->assertStatus(422);   // support principals can never own a key
        $this->assertSame(0, DB::table('api_keys')->count());
    }

    // ---- authentication --------------------------------------------------------------------------------------------

    public function test_the_signature_format_is_the_documented_one(): void
    {
        // receivers verify with exactly this recipe (docs/PUBLIC_API.md): hex HMAC-SHA256 over "<t>.<raw body>"
        $this->assertSame('t=1700000000,v1=b3438763ab46d9634b9844c8722818eaf523f803db1327484c6067462a5fd6d1', WebhookService::signature('whsec_x', '{"a":1}', 1700000000));
    }

    public function test_every_authentication_failure_looks_the_same(): void
    {
        [$token, $id] = $this->key();
        $revoked = $this->key()[0];
        $this->actingAs($this->admin)->deleteJson('/api/v1/integrations/api-keys/'.DB::table('api_keys')->where('prefix', substr($revoked, 4, 8))->value('id'))->assertOk();
        $expired = $this->key(extra: ['expires_at' => now()->addHour()->toDateTimeString()])[0];
        DB::table('api_keys')->where('prefix', substr($expired, 4, 8))->update(['expires_at' => now()->subMinute()]);   // it has now lapsed
        $gone = $this->key(as: $this->userWithPermissions(['list-clients']))[0];
        $this->actingAs($this->admin);
        User::whereKey(DB::table('api_keys')->where('prefix', substr($gone, 4, 8))->value('user_id'))->delete();

        $prefix = substr($token, 0, 13);   // lfk_<prefix>_ : right shape, right prefix, wrong secret
        $bodies = [];
        foreach ([null, 'garbage', 'lfk_'.str_repeat('0', 8).'_'.str_repeat('a', 32), $prefix.str_repeat('b', 32), $revoked, $expired, $gone] as $t) {
            $bodies[] = $this->pub('/clients', $t)->assertStatus(401)->assertJsonPath('code', 'invalid_api_key')->getContent();
        }
        // a key row that somehow belongs to a support principal never authenticates (defence in depth: creation refuses it too)
        $staff = User::factory()->create(['platform_staff_id' => 7]);
        DB::table('api_keys')->where('prefix', substr($token, 4, 8))->update(['user_id' => $staff->id]);
        $this->pub('/clients', $token)->assertStatus(401);
        DB::table('api_keys')->where('prefix', substr($token, 4, 8))->update(['user_id' => $this->reader->id]);
        $this->assertCount(1, array_unique($bodies));          // nothing tells the caller which case they hit
        $this->pub('/clients', $token)->assertOk();
    }

    public function test_an_api_key_is_not_a_session_and_a_session_is_not_an_api_key(): void
    {
        [$token] = $this->key();
        $this->withToken($token)->getJson('/api/v1/clients')->assertStatus(401);           // the key does not open the normal API
        $this->flushHeaders();
        $this->actingAs($this->reader)->getJson('/api/v1/public/clients')->assertStatus(401);   // a login does not open the public API
    }

    public function test_the_public_api_exists_only_on_v1_and_is_read_only(): void
    {
        [$token] = $this->key();
        $this->withToken($token)->getJson('/api/public/clients')->assertNotFound();
        foreach (['post', 'put', 'patch', 'delete'] as $m) {
            $this->withToken($token)->json($m, '/api/v1/public/clients', ['name' => 'x'])->assertStatus(405);
        }
    }

    // ---- scopes and permissions ------------------------------------------------------------------------------------

    public function test_a_key_can_do_only_what_its_scopes_and_its_user_allow(): void
    {
        Client::factory()->create();
        [$clientsOnly] = $this->key(['clients:read']);
        $this->pub('/clients', $clientsOnly)->assertOk();
        $this->pub('/cases', $clientsOnly)->assertForbidden()->assertJsonPath('code', 'insufficient_scope');
        $this->pub('/hearings', $clientsOnly)->assertForbidden();

        $weakUser = $this->userWithPermissions(['list-clients']);                            // may list clients but not cases
        [$wide] = $this->key(['clients:read', 'cases:read'], $weakUser);
        $this->pub('/clients', $wide)->assertOk();
        $this->pub('/cases', $wide)->assertForbidden();                                      // scope alone is not enough
    }

    public function test_removing_the_users_permission_stops_the_key_at_once(): void
    {
        [$token] = $this->key(['clients:read']);
        $this->pub('/clients', $token)->assertOk();
        $this->reader->roles()->detach();
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $this->pub('/clients', $token)->assertForbidden();
    }

    public function test_a_plan_without_api_access_switches_every_key_off(): void
    {
        [$token] = $this->key();
        $this->pub('/clients', $token)->assertOk();
        $this->plan('starter');
        $this->pub('/clients', $token)->assertForbidden()->assertJsonPath('code', 'entitlement_required');
    }

    // ---- data ------------------------------------------------------------------------------------------------------

    public function test_listing_pagination_filters_and_the_exact_field_allow_list(): void
    {
        $advocate = $this->userWithPermissions([]);
        $clients = Client::factory()->count(5)->create();
        $case = Cases::factory()->create(['client_id' => $clients[0]->id, 'description' => 'PRIVILEGED ADVICE SUMMARY', 'opposing_party' => 'Secret Opponent Ltd', 'police_station' => 'Central']);
        $hearing = Hearing::factory()->create(['case_id' => $case->id, 'notes' => 'PRIVATE HEARING NOTE', 'outcome' => 'PRIVATE OUTCOME']);
        [$token] = $this->key();

        $p = $this->pub('/clients?per_page=2&page=2', $token)->assertOk();
        $this->assertCount(2, $p->json('data'));
        $this->assertSame([2, 2, 5, 3], [$p->json('meta.page'), $p->json('meta.per_page'), $p->json('meta.total'), $p->json('meta.last_page')]);
        $this->assertCount(5, $this->pub('/clients?per_page=100000', $token)->json('data'));
        $this->assertSame(100, $this->pub('/clients?per_page=100000', $token)->json('meta.per_page'));    // capped
        $this->assertSame(1, $this->pub('/clients?per_page=0', $token)->json('meta.per_page'));
        $this->assertSame('1', $this->pub('/clients', $token)->headers->get('X-RateLimit-Limit') ? '1' : '0');

        $this->assertEqualsCanonicalizing(['id', 'name', 'phone_number', 'extra_phone_number', 'address', 'created_at', 'updated_at'], array_keys($this->pub('/clients/'.$clients[0]->public_id, $token)->assertOk()->json('data')));
        $m = $this->pub('/cases/'.$case->public_id, $token)->assertOk();
        $this->assertEqualsCanonicalizing(['id', 'case_number', 'case_type', 'client_id', 'status', 'start_date', 'end_date', 'created_at', 'updated_at'], array_keys($m->json('data')));
        $h = $this->pub('/hearings/'.$hearing->public_id, $token)->assertOk();
        $this->assertEqualsCanonicalizing(['id', 'case_id', 'hearing_date', 'hearing_type', 'created_at', 'updated_at'], array_keys($h->json('data')));
        // DATA-9: only public ids leave the system, including references to other records
        $this->assertSame([$hearing->public_id, $case->public_id], [$h->json('data.id'), $h->json('data.case_id')]);
        $this->assertSame([$case->public_id, $clients[0]->public_id], [$m->json('data.id'), $m->json('data.client_id')]);
        $this->pub('/clients/'.$clients[0]->id, $token)->assertNotFound();   // integer ids are never accepted
        foreach ([$m->getContent(), $h->getContent(), $this->pub('/cases', $token)->getContent(), $this->pub('/hearings', $token)->getContent()] as $body) {
            foreach (['PRIVILEGED ADVICE', 'Secret Opponent', 'PRIVATE HEARING', 'PRIVATE OUTCOME', 'Central'] as $leak) {
                $this->assertStringNotContainsString($leak, $body);
            }
        }

        $this->pub('/clients/'.Client::newPublicId(), $token)->assertNotFound();
        $this->pub('/clients?updated_since=not-a-date', $token)->assertStatus(422);
        DB::table('clients')->where('id', $clients[1]->id)->update(['updated_at' => now()->addDay()]);
        $this->assertSame([$clients[1]->public_id], array_column($this->pub('/clients?updated_since='.urlencode(now()->addHours(12)->toIso8601String()), $token)->json('data'), 'id'));
    }

    public function test_deleted_records_disappear_and_matter_walls_apply_to_the_key(): void
    {
        $client = Client::factory()->create();
        $open = Cases::factory()->create(['client_id' => $client->id]);
        $walled = Cases::factory()->create(['client_id' => $client->id]);
        EthicalScreen::create(['user_id' => $this->reader->id, 'case_id' => $walled->id, 'reason' => 'conflict']);
        $gone = Client::factory()->create();
        $gone->delete();
        [$token] = $this->key();

        $this->assertSame([$open->public_id], array_column($this->pub('/cases', $token)->json('data'), 'id'));    // the wall holds for the key, as for the person
        $this->pub('/cases/'.$walled->public_id, $token)->assertNotFound();
        $this->pub('/clients/'.$gone->public_id, $token)->assertNotFound();
    }

    // ---- rate limits, usage, revocation ----------------------------------------------------------------------------

    public function test_rate_limits_are_per_key(): void
    {
        [$a] = $this->key(['clients:read'], null, ['rate_limit' => 3]);
        [$b] = $this->key(['clients:read'], null, ['rate_limit' => 3]);
        foreach ([2, 1, 0] as $remaining) {
            $this->assertSame((string) $remaining, $this->pub('/clients', $a)->assertOk()->headers->get('X-RateLimit-Remaining'));
        }
        $r = $this->pub('/clients', $a)->assertStatus(429)->assertJsonPath('code', 'rate_limited');
        $this->assertGreaterThan(0, (int) $r->headers->get('Retry-After'));
        $this->pub('/clients', $b)->assertOk();                                  // another key is unaffected
        $this->travel(61)->seconds();
        $this->pub('/clients', $a)->assertOk();                                  // the window passed
    }

    public function test_usage_is_recorded_and_revocation_is_immediate_and_audited(): void
    {
        [$token, $id] = $this->key(['clients:read']);
        $this->assertNull(DB::table('api_keys')->find($id)->last_used_at);
        $this->pub('/clients', $token)->assertOk();
        $this->assertNotNull(DB::table('api_keys')->find($id)->last_used_at);

        $this->actingAs($this->admin)->deleteJson('/api/v1/integrations/api-keys/'.$id)->assertOk()->assertJsonPath('data.revoked_at', fn ($v) => $v !== null);
        $this->pub('/clients', $token)->assertStatus(401);
        $this->actingAs($this->admin)->deleteJson('/api/v1/integrations/api-keys/'.$id)->assertOk();            // idempotent
        $this->assertSame(1, DB::table('audits')->where('event', 'api_key_revoked')->count());
        $this->actingAs($this->admin)->deleteJson('/api/v1/integrations/api-keys/9999')->assertNotFound();
    }

    // ---- the contract ----------------------------------------------------------------------------------------------

    public function test_the_published_spec_and_the_routes_describe_the_same_api(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi-public.yaml'));
        $declared = [];
        foreach ($spec['paths'] as $path => $ops) {
            foreach ($ops as $method => $_) {
                $declared[] = strtoupper($method).' api/v1'.preg_replace('/\{[a-z_]+\}/', '{id}', $path);
            }
        }
        $actual = [];
        foreach (Route::getRoutes() as $r) {
            if (collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'api.key:'))) {
                $actual[] = 'GET '.preg_replace('/\{[a-z_]+\}/', '{id}', $r->uri());
            }
        }
        sort($declared);
        sort($actual);
        $this->assertSame($actual, $declared, 'docs/openapi-public.yaml and the api.key routes have drifted apart.');

        // every scope a route asks for exists, and each scope names a real permission
        foreach (Route::getRoutes() as $r) {
            foreach ($r->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'api.key:')) {
                    $this->assertArrayHasKey(substr($m, 8), config('public_api.scopes'));
                }
            }
        }
        foreach (config('public_api.scopes') as $scope => $perm) {
            $this->assertNotNull(\Spatie\Permission\Models\Permission::where('name', $perm)->first() ?? \Spatie\Permission\Models\Permission::findOrCreate($perm, 'web'));
        }
    }

    // ---- webhooks: registration ------------------------------------------------------------------------------------

    private function hook(array $events = ['*'], string $url = 'https://hooks.example.test/in')
    {
        return $this->actingAs($this->admin)->postJson('/api/v1/integrations/webhooks', ['url' => $url, 'events' => $events]);
    }

    /** @return array{0:int,1:string} */
    private function makeHook(array $events = ['*'], string $url = 'https://hooks.example.test/in'): array
    {
        $r = $this->hook($events, $url)->assertCreated();
        app('auth')->forgetGuards();

        return [$r->json('data.id'), $r->json('data.secret')];
    }

    private function deliveries(?int $endpoint = null)
    {
        return DB::table('webhook_deliveries')->when($endpoint, fn ($q) => $q->where('endpoint_id', $endpoint))->orderBy('id')->get();
    }

    public function test_a_webhook_secret_is_shown_once_stored_encrypted_and_never_listed(): void
    {
        $r = $this->hook(['case.created'])->assertCreated();
        $secret = $r->json('data.secret');
        $this->assertStringStartsWith('whsec_', $secret);
        $stored = DB::table('webhook_endpoints')->value('secret');
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame($secret, Crypt::decryptString($stored));
        $list = $this->actingAs($this->admin)->getJson('/api/v1/integrations/webhooks')->assertOk();
        $this->assertStringNotContainsString($secret, $list->getContent());
        $this->assertStringNotContainsString('"secret"', $list->getContent());
        $this->assertStringNotContainsString($secret, json_encode(DB::table('audits')->get()));
    }

    public function test_bad_webhook_registrations_are_refused(): void
    {
        config(['sso.block_private_hosts' => true, 'sso.allow_http' => false]);
        foreach ([['http://hooks.example.test/in', ['*']], ['https://127.0.0.1/in', ['*']], ['https://169.254.169.254/latest', ['*']], ['https://10.0.0.5/in', ['*']],
            ['https://user:pw@hooks.example.test/in', ['*']], ['ftp://x.example.test', ['*']], ['not a url', ['*']], ['https://hooks.example.test/in', ['case.exploded']], ['https://hooks.example.test/in', []]] as [$url, $events]) {
            $this->hook($events, $url)->assertStatus(422);
        }
        $this->assertSame(0, DB::table('webhook_endpoints')->count());
        config(['public_api.webhooks.max_endpoints' => 1, 'sso.block_private_hosts' => false]);
        $this->hook()->assertCreated();
        $this->hook()->assertStatus(422);
    }

    public function test_every_management_route_needs_the_permission(): void
    {
        $nobody = $this->userWithPermissions(['list-clients']);
        foreach ([['get', '/api-keys'], ['post', '/api-keys'], ['delete', '/api-keys/1'], ['get', '/webhooks'], ['post', '/webhooks'], ['put', '/webhooks/1'], ['delete', '/webhooks/1'],
            ['post', '/webhooks/1/rotate-secret'], ['post', '/webhooks/1/ping'], ['get', '/webhooks/1/deliveries'], ['post', '/webhooks/deliveries/1/replay']] as [$m, $p]) {
            $this->actingAs($nobody)->json($m, '/api/v1/integrations'.$p, [])->assertForbidden();
        }
    }

    // ---- webhooks: events ------------------------------------------------------------------------------------------

    public function test_events_fan_out_to_subscribed_active_endpoints_with_the_safe_payload(): void
    {
        [$all] = $this->makeHook(['*']);
        [$onlyCases] = $this->makeHook(['case.created', 'case.updated'], 'https://hooks.example.test/cases');
        [$off] = $this->makeHook(['*'], 'https://hooks.example.test/off');
        $this->actingAs($this->admin)->putJson("/api/v1/integrations/webhooks/{$off}", ['active' => false])->assertOk();

        $client = Client::factory()->create();
        $this->assertCount(1, $this->deliveries($all));
        $this->assertCount(0, $this->deliveries($onlyCases));
        $this->assertCount(0, $this->deliveries($off));

        $case = Cases::factory()->create(['client_id' => $client->id, 'description' => 'PRIVILEGED SUMMARY', 'opposing_party' => 'Hidden Party']);
        $this->assertSame(['client.created', 'case.created'], $this->deliveries($all)->pluck('event')->all());
        $this->assertSame(['case.created'], $this->deliveries($onlyCases)->pluck('event')->all());

        // always a real change (this was flaky: the factory sometimes picked 'civil' already, so nothing changed and no event fired)
        $case->update(['case_type' => $case->case_type === 'civil' ? 'criminal' : 'civil']);
        $case->delete();
        Hearing::factory()->create(['case_id' => $case->id]);
        $events = $this->deliveries($all)->pluck('event')->all();
        $this->assertContains('case.updated', $events);
        $this->assertContains('case.deleted', $events);
        $this->assertContains('hearing.created', $events);

        $d = $this->deliveries($all)[1];
        $p = json_decode($d->payload, true);
        $this->assertSame($d->event_id, $p['id']);
        $this->assertSame('case.created', $p['type']);
        $this->assertSame($case->public_id, $p['data']['object']['id']);
        $this->assertStringNotContainsString('PRIVILEGED SUMMARY', $d->payload);
        $this->assertStringNotContainsString('Hidden Party', $d->payload);
        $this->assertCount(count(array_unique($this->deliveries($all)->pluck('event_id')->all())), $this->deliveries($all));      // unique event ids
    }

    public function test_no_events_without_api_access_and_a_broken_webhook_table_never_breaks_saving(): void
    {
        [$id] = $this->makeHook();
        $this->plan('starter');
        Client::factory()->create();
        $this->assertCount(0, $this->deliveries($id));

        $this->plan('self_hosted');
        \Illuminate\Support\Facades\Schema::drop('webhook_deliveries');
        $c = Client::factory()->create();                                   // the integration is broken; the firm's work is not
        $this->assertNotNull(Client::find($c->id));
    }

    public function test_an_event_rolls_back_with_the_transaction_that_caused_it(): void
    {
        [$id] = $this->makeHook();
        try {
            DB::transaction(function () {
                Client::factory()->create();
                throw new \RuntimeException('abort');
            });
        } catch (\RuntimeException) {
        }
        $this->assertCount(0, $this->deliveries($id));      // nothing is announced about something that did not happen
    }

    // ---- webhooks: delivery ----------------------------------------------------------------------------------------

    private function svc(): WebhookService
    {
        return app(WebhookService::class);
    }

    public function test_a_delivery_is_signed_and_marked_delivered_on_2xx(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
        [$id, $secret] = $this->makeHook();
        $client = Client::factory()->create();
        $r = $this->svc()->runDue();
        $this->assertSame(['sent' => 1, 'delivered' => 1, 'retry' => 0, 'dead' => 0], $r);

        $d = $this->deliveries($id)[0];
        $this->assertSame(['delivered', 1, 200], [$d->status, (int) $d->attempts, (int) $d->last_status_code]);
        Http::assertSent(function (HttpRequest $req) use ($d, $secret) {
            $ts = (int) $req->header('X-Webhook-Timestamp')[0];
            $this->assertSame($d->payload, $req->body());
            $this->assertSame(WebhookService::signature($secret, $d->payload, $ts), $req->header('X-Webhook-Signature')[0]);
            $this->assertSame([$d->event_id], $req->header('X-Webhook-Id'));
            $this->assertSame(['client.created'], $req->header('X-Webhook-Event'));
            $this->assertSame('https://hooks.example.test/in', $req->url());

            return true;
        });
        $this->assertSame(0, $this->svc()->runDue()['sent']);              // nothing left to do
    }

    public function test_failures_retry_with_backoff_then_die_and_the_endpoint_is_eventually_switched_off(): void
    {
        config(['public_api.webhooks.disable_after_dead' => 2]);
        Http::fake(['hooks.example.test/*' => Http::response('boom', 500)]);
        [$id] = $this->makeHook();
        Client::factory()->create();
        $t = now();

        $this->assertSame(1, $this->svc()->runDue(100, $t)['retry']);
        $d = $this->deliveries($id)[0];
        $this->assertSame(['retry', 1, 500, 'HTTP 500'], [$d->status, (int) $d->attempts, (int) $d->last_status_code, $d->last_error]);
        $this->assertSame($t->copy()->addMinutes(1)->toDateTimeString(), (string) $d->next_attempt_at);

        $this->assertSame(0, $this->svc()->runDue(100, $t->copy()->addSeconds(59))['sent']);        // not due yet
        foreach ([[5, 2], [30, 3], [120, 4], [360, 5]] as [$minutes, $attempts]) {
            $at = \Carbon\Carbon::parse($this->deliveries($id)[0]->next_attempt_at)->addSecond();
            $this->assertSame(1, $this->svc()->runDue(100, $at)['retry']);
            $d = $this->deliveries($id)[0];
            $this->assertSame($attempts, (int) $d->attempts);
            $this->assertSame($at->copy()->addMinutes($minutes)->toDateTimeString(), (string) $d->next_attempt_at);
        }
        $at = \Carbon\Carbon::parse($this->deliveries($id)[0]->next_attempt_at)->addSecond();
        $this->assertSame(1, $this->svc()->runDue(100, $at)['dead']);                              // 6th attempt
        $d = $this->deliveries($id)[0];
        $this->assertSame(['dead', 6], [$d->status, (int) $d->attempts]);
        $this->assertNull($d->next_attempt_at);
        $this->assertTrue((bool) DB::table('webhook_endpoints')->find($id)->active);               // one dead delivery: still on
        $this->assertSame(0, $this->svc()->runDue(100, $at->copy()->addYear())['sent']);          // dead ones are not retried by themselves

        Client::factory()->create();                                                                // a second event dies too
        for ($i = 0; $i < 6; $i++) {
            $this->svc()->runDue(100, now()->addYears(2 + $i));
        }
        $e = DB::table('webhook_endpoints')->find($id);
        $this->assertFalse((bool) $e->active);                                                      // switched off after 2 dead in a row
        $this->assertNotNull($e->disabled_reason);
    }

    public function test_a_success_resets_the_failure_streak_and_connection_errors_are_retried(): void
    {
        $down = true;   // Http::fake stubs registered first win, so the stub reads mutable state
        Http::fake(['hooks.example.test/*' => function () use (&$down) {
            if ($down) {
                throw new ConnectionException('cURL error 28: timed out');
            }

            return Http::response('', 204);
        }]);
        [$id] = $this->makeHook();
        DB::table('webhook_endpoints')->where('id', $id)->update(['consecutive_dead' => 5]);
        Client::factory()->create();
        $this->assertSame(1, $this->svc()->runDue()['retry']);
        $this->assertStringContainsString('timed out', $this->deliveries($id)[0]->last_error);
        $this->assertNull($this->deliveries($id)[0]->last_status_code);

        $down = false;
        $this->assertSame(1, $this->svc()->runDue(100, now()->addMinutes(2))['delivered']);
        $this->assertSame(0, (int) DB::table('webhook_endpoints')->find($id)->consecutive_dead);
    }

    public function test_redirects_are_not_followed_and_count_as_failures(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('', 302, ['Location' => 'https://elsewhere.example.test/'])]);
        [$id] = $this->makeHook();
        Client::factory()->create();
        $this->assertSame(1, $this->svc()->runDue()['retry']);
        $this->assertSame(302, (int) $this->deliveries($id)[0]->last_status_code);
        Http::assertSentCount(1);
    }

    public function test_the_url_is_checked_again_at_delivery_time(): void
    {
        Http::fake();
        [$id] = $this->makeHook();
        Client::factory()->create();
        config(['sso.block_private_hosts' => true]);
        DB::table('webhook_endpoints')->where('id', $id)->update(['url' => 'https://169.254.169.254/latest/meta-data']);    // DNS changed after registration
        $this->assertSame(1, $this->svc()->runDue()['retry']);
        Http::assertNothingSent();
        $this->assertStringContainsString('refused', $this->deliveries($id)[0]->last_error);
    }

    public function test_a_delivery_in_flight_is_not_sent_twice_and_a_crashed_one_is_picked_up(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
        [$id] = $this->makeHook();
        Client::factory()->create();
        DB::table('webhook_deliveries')->update(['status' => 'sending', 'locked_until' => now()->addMinutes(1)]);
        $this->assertSame(0, $this->svc()->runDue()['sent']);
        Http::assertNothingSent();
        $this->assertSame(1, $this->svc()->runDue(100, now()->addMinutes(5))['delivered']);      // the lock expired: a worker died
    }

    public function test_replay_resends_the_same_event_only_when_it_is_finished(): void
    {
        Http::fake(['hooks.example.test/*' => Http::sequence()->push('x', 500)->push('x', 500)->push('x', 500)->push('x', 500)->push('x', 500)->push('x', 500)->push('ok', 200)]);
        [$id] = $this->makeHook();
        Client::factory()->create();
        $first = $this->deliveries($id)[0];
        $this->actingAs($this->admin)->postJson('/api/v1/integrations/webhooks/deliveries/'.$first->id.'/replay')->assertStatus(409);      // pending: nothing to replay yet

        for ($i = 0; $i < 6; $i++) {
            $this->svc()->runDue(100, now()->addYears($i + 1));
        }
        $this->assertSame('dead', $this->deliveries($id)[0]->status);
        $r = $this->actingAs($this->admin)->postJson('/api/v1/integrations/webhooks/deliveries/'.$first->id.'/replay')->assertOk();
        $this->assertSame(['pending', 0], [$r->json('data.status'), (int) $r->json('data.attempts')]);
        $this->assertSame(1, $this->svc()->runDue()['delivered']);
        $this->assertSame($first->event_id, $this->deliveries($id)[0]->event_id);                 // same event id: receivers can de-duplicate
        $this->assertCount(1, $this->deliveries($id));
        $this->assertSame(1, DB::table('audits')->where('event', 'webhook_replayed')->count());
        $this->actingAs($this->admin)->postJson('/api/v1/integrations/webhooks/deliveries/99999/replay')->assertNotFound();
    }

    public function test_rotating_the_secret_invalidates_the_old_one_and_ping_works(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
        [$id, $old] = $this->makeHook();
        $new = $this->actingAs($this->admin)->postJson("/api/v1/integrations/webhooks/{$id}/rotate-secret")->assertOk()->json('data.secret');
        $this->assertNotSame($old, $new);
        $this->actingAs($this->admin)->postJson("/api/v1/integrations/webhooks/{$id}/ping")->assertStatus(202);
        $this->svc()->runDue();
        Http::assertSent(function (HttpRequest $req) use ($old, $new) {
            $ts = (int) $req->header('X-Webhook-Timestamp')[0];
            $this->assertSame(WebhookService::signature($new, $req->body(), $ts), $req->header('X-Webhook-Signature')[0]);
            $this->assertNotSame(WebhookService::signature($old, $req->body(), $ts), $req->header('X-Webhook-Signature')[0]);
            $this->assertSame(['ping'], $req->header('X-Webhook-Event'));

            return true;
        });
    }

    public function test_deleting_an_endpoint_removes_its_deliveries_and_updates_are_validated(): void
    {
        [$id] = $this->makeHook();
        Client::factory()->create();
        $this->actingAs($this->admin)->putJson("/api/v1/integrations/webhooks/{$id}", ['events' => ['nope']])->assertStatus(422);
        config(['sso.block_private_hosts' => true]);
        $this->actingAs($this->admin)->putJson("/api/v1/integrations/webhooks/{$id}", ['url' => 'https://127.0.0.1/x'])->assertStatus(422);
        $this->actingAs($this->admin)->putJson('/api/v1/integrations/webhooks/9999', ['active' => false])->assertNotFound();
        $this->assertCount(1, $this->actingAs($this->admin)->getJson("/api/v1/integrations/webhooks/{$id}/deliveries")->json('data'));
        $this->actingAs($this->admin)->deleteJson("/api/v1/integrations/webhooks/{$id}")->assertOk();
        $this->assertSame(0, DB::table('webhook_deliveries')->count());
        $this->assertSame(0, DB::table('webhook_endpoints')->count());
    }

    public function test_the_deliveries_view_never_shows_payloads_or_secrets(): void
    {
        [$id, $secret] = $this->makeHook();
        Client::factory()->create(['name' => 'Confidential Client Name']);
        $body = $this->actingAs($this->admin)->getJson("/api/v1/integrations/webhooks/{$id}/deliveries")->assertOk()->getContent();
        $this->assertStringNotContainsString('Confidential Client Name', $body);
        $this->assertStringNotContainsString($secret, $body);
    }

    public function test_the_deliver_command_sends_and_prunes_old_logs(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response('ok', 200)]);
        [$id] = $this->makeHook();
        Client::factory()->create();
        $this->artisan('webhooks:deliver')->expectsOutputToContain('delivered=1')->assertSuccessful();
        DB::table('webhook_deliveries')->update(['updated_at' => now()->subDays(40)]);
        $this->artisan('webhooks:deliver')->assertSuccessful();
        $this->assertSame(0, DB::table('webhook_deliveries')->count());
    }
}
