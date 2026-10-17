<?php

namespace Tests\Feature;

use App\Calendar\CalendarSync;
use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Hearing;
use App\Models\HearingType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-16: court diary, iCalendar feeds, and two-way sync with Google / Microsoft calendars. */
class CalendarTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $lawyer;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 08:00:00'));
        $this->lawyer = $this->userWithPermissions([P::LIST_HEARINGS, P::CREATE_HEARINGS, P::UPDATE_HEARINGS, P::LIST_CASES]);
        $this->lawyer->forceFill(['name' => 'Njeri Kamau'])->save();
        $this->matter = Cases::factory()->create(['case_number' => 'HCCC 44/2026', 'assigned_to' => $this->lawyer->id]);
    }

    private function hearing(string $date, ?string $time = null, ?Cases $m = null, array $extra = []): Hearing
    {
        return Hearing::create($extra + ['case_id' => ($m ?? $this->matter)->id, 'court_id' => ($m ?? $this->matter)->court_id, 'hearing_date' => $date,
            'hearing_type' => HearingType::factory()->create(['name' => 'Mention'])->id, 'notes' => 'Strategy: concede costs', 'start_time' => $time]);
    }

    private function task(string $due, string $title = 'File list of documents'): Task
    {
        return Task::create(['title' => $title, 'description' => 'Before mention', 'assigned_to' => $this->lawyer->id, 'due_date' => $due, 'priority' => 'high', 'task_status' => 'pending']);
    }

    public function test_hearings_take_their_court_details_and_the_diary_lists_them_day_by_day(): void
    {
        $this->actingAs($this->lawyer)->postJson('/api/v1/hearings', ['case_id' => $this->matter->id, 'court_id' => $this->matter->court_id, 'hearing_date' => '07/10/2026',
            'hearing_type' => HearingType::factory()->create()->id, 'start_time' => '09:30', 'duration_minutes' => 90, 'courtroom' => 'Court 4',
            'judicial_officer' => 'Hon. Lady Justice Achieng', 'efiling_reference' => 'HCCOMM/E044/2026'])->assertCreated()
            ->assertJsonPath('data.start_time', '09:30')->assertJsonPath('data.courtroom', 'Court 4');
        $this->hearing('2026-10-07', null);
        $this->hearing('2026-10-09', '14:00');
        $this->hearing('2026-12-25');   // outside the default fortnight

        $days = $this->getJson('/api/v1/court-diary?from=2026-10-05&to=2026-10-18')->assertOk()->json('data.days');
        $this->assertSame(['2026-10-07', '2026-10-09'], array_keys($days));
        $this->assertSame(['09:30', null], array_column($days['2026-10-07'], 'start_time'), 'timed hearings first');
        $this->assertSame('Hon. Lady Justice Achieng', $days['2026-10-07'][0]['judicial_officer']);
        $this->getJson('/api/v1/court-diary?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
        $this->actingAs($this->userWithPermissions([P::LIST_CASES]))->getJson('/api/v1/court-diary')->assertForbidden();
    }

    public function test_a_subscription_feed_is_valid_icalendar_and_shows_what_its_owner_may_see_now(): void
    {
        $this->hearing('2026-10-07', '09:30', null, ['courtroom' => 'Court 4']);
        $this->task('2026-10-06');
        $other = Cases::factory()->create(['case_number' => 'NOT-MINE']);
        $this->hearing('2026-10-08', null, $other);

        $url = $this->actingAs($this->lawyer)->postJson('/api/v1/calendar/feeds', ['scope' => 'mine'])->assertCreated()->json('data.url');
        $path = parse_url($url, PHP_URL_PATH);
        $this->app['auth']->forgetGuards();
        $ics = $this->get($path)->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=utf-8')->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        $this->assertStringContainsString("DTSTART:20261007T063000Z\r\nDTEND:20261007T073000Z", $ics, '09:30 Nairobi is 06:30 UTC, default one hour');
        $this->assertStringContainsString('SUMMARY:Mention: HCCC 44/2026', $ics);
        $this->assertStringContainsString('LOCATION:Court 4\,', $ics, 'commas are escaped');
        $this->assertStringContainsString("DTSTART;VALUE=DATE:20261006\r\nDTEND;VALUE=DATE:20261007", $ics, 'a task is an all-day entry');
        $this->assertStringNotContainsString('NOT-MINE', $ics);
        $this->assertStringNotContainsString('concede costs', $ics, 'hearing notes never leave the system');
        foreach (explode("\r\n", $ics) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line), 'lines are folded');
        }

        // the court diary feed needs the hearings permission and shows every visible matter
        $diary = parse_url($this->actingAs($this->lawyer)->postJson('/api/v1/calendar/feeds', ['scope' => 'diary'])->json('data.url'), PHP_URL_PATH);
        $this->app['auth']->forgetGuards();
        $this->assertStringContainsString('NOT-MINE', $this->get($diary)->getContent());
        $this->actingAs($this->userWithPermissions([P::LIST_CASES]))->postJson('/api/v1/calendar/feeds', ['scope' => 'diary'])->assertForbidden();

        // turned off: gone
        $id = DB::table('calendar_feeds')->where('scope', 'mine')->value('id');
        $this->actingAs($this->lawyer)->deleteJson("/api/v1/calendar/feeds/{$id}")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($path)->assertNotFound();
        $this->get('/calendar/'.str_repeat('a', 48).'.ics')->assertNotFound();
    }

    public function test_connecting_google_goes_through_oauth_bound_to_the_person_and_the_firm(): void
    {
        config(['calendar.google.client_id' => 'gid', 'calendar.google.client_secret' => 'gsecret']);
        Bus::fake();
        $this->actingAs($this->lawyer)->getJson('/api/v1/calendar/connections')->assertJsonPath('data.available.google', true)->assertJsonPath('data.available.microsoft', false);
        $this->postJson('/api/v1/calendar/connect/microsoft')->assertUnprocessable();
        $auth = $this->postJson('/api/v1/calendar/connect/google')->assertOk()->json('data.url');
        parse_str((string) parse_url($auth, PHP_URL_QUERY), $q);
        $this->assertSame('offline', $q['access_type']);
        $this->assertStringContainsString('calendar.events', $q['scope']);

        $claims = rtrim(strtr(base64_encode(json_encode(['email' => 'njeri@firm.test'])), '+/', '-_'), '=');
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'at1', 'refresh_token' => 'rt1', 'expires_in' => 3600, 'id_token' => "h.{$claims}.s"])]);
        $this->get('/calendar/oauth/callback?state=forged&code=x')->assertRedirect('/calendar-sync?calendar=expired');
        $this->get('/calendar/oauth/callback?'.http_build_query(['state' => $q['state'], 'code' => 'the-code']))->assertRedirect('/calendar-sync?calendar=connected');
        $c = DB::table('calendar_connections')->first();
        $this->assertSame(['google', 'njeri@firm.test', $this->lawyer->id], [$c->provider, $c->account_email, (int) $c->user_id]);
        $this->assertSame('rt1', Crypt::decryptString($c->refresh_token), 'tokens are stored encrypted');
        $this->assertNotSame('at1', $c->access_token);
        Http::assertSent(fn ($r) => $r->url() === 'https://oauth2.googleapis.com/token' && $r['code'] === 'the-code' && $r['redirect_uri'] === config('calendar.oauth_redirect'));

        // an expired state is refused
        $this->travel(11)->minutes();
        $this->get('/calendar/oauth/callback?'.http_build_query(['state' => $q['state'], 'code' => 'again']))->assertRedirect('/calendar-sync?calendar=expired');
    }

    private function googleConnection(): int
    {
        // changes would otherwise sync inline on the sync queue; these tests run the sync themselves
        Bus::fake([\App\Jobs\SyncCalendarConnection::class]);
        config(['calendar.google.client_id' => 'gid', 'calendar.google.client_secret' => 'gsecret']);

        return DB::table('calendar_connections')->insertGetId(['user_id' => $this->lawyer->id, 'provider' => 'google', 'access_token' => Crypt::encryptString('at1'),
            'refresh_token' => Crypt::encryptString('rt1'), 'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_sync_sends_new_changed_and_removed_items_once(): void
    {
        $h = $this->hearing('2026-10-07', '09:30');
        $t = $this->task('2026-10-06');
        $id = $this->googleConnection();
        $created = 0;
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => [], 'nextSyncToken' => 'sync-1']),
            'www.googleapis.com/calendar/v3/calendars/primary/events' => function () use (&$created) {
                return Http::response(['id' => 'ev'.(++$created)]);
            },
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response(['id' => 'kept'], 200),
        ]);

        $this->assertSame(['created' => 2, 'updated' => 0, 'removed' => 0, 'tasks_moved' => 0, 'hearings_restored' => 0], app(CalendarSync::class)->run($id));
        $this->assertSame('sync-1', DB::table('calendar_connections')->value('sync_token'));
        $this->assertSame(0, array_sum(app(CalendarSync::class)->run($id)), 'nothing changed: nothing sent');

        $h->update(['courtroom' => 'Court 7']);
        Bus::assertDispatched(\App\Jobs\SyncCalendarConnection::class, fn ($j) => $j->connectionId === $id);   // a change triggers a sync
        Http::assertSent(fn ($r) => $r->method() === 'POST' && ($r['start']['dateTime'] ?? null) === '2026-10-07T09:30:00+03:00' && $r['start']['timeZone'] === 'Africa/Nairobi');
        $this->assertSame(1, app(CalendarSync::class)->run($id)['updated']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r['location'] ?? '', 'Court 7'));

        $t->update(['task_status' => 'completed']);
        $this->assertSame(1, app(CalendarSync::class)->run($id)['removed']);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertSame(1, DB::table('calendar_event_links')->count());
    }

    public function test_a_task_moved_in_the_calendar_moves_and_a_moved_hearing_is_put_back(): void
    {
        $h = $this->hearing('2026-10-07');
        $t = $this->task('2026-10-06');
        $id = $this->googleConnection();
        DB::table('calendar_event_links')->insert([
            ['calendar_connection_id' => $id, 'source_type' => 'hearing', 'source_id' => $h->id, 'external_id' => 'evH', 'synced_hash' => 'x', 'synced_date' => '2026-10-07', 'created_at' => now(), 'updated_at' => now()],
            ['calendar_connection_id' => $id, 'source_type' => 'task', 'source_id' => $t->id, 'external_id' => 'evT', 'synced_hash' => 'x', 'synced_date' => '2026-10-06', 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('calendar_connections')->update(['sync_token' => 'sync-1']);
        Http::fake([
            'www.googleapis.com/calendar/v3/calendars/primary/events?*' => Http::response(['items' => [
                ['id' => 'evT', 'status' => 'confirmed', 'start' => ['date' => '2026-10-09']],
                ['id' => 'evH', 'status' => 'confirmed', 'start' => ['date' => '2026-10-10']],
                ['id' => 'someone-elses', 'status' => 'confirmed', 'start' => ['date' => '2026-10-10']],
            ], 'nextSyncToken' => 'sync-2']),
            'www.googleapis.com/calendar/v3/calendars/primary/events/*' => Http::response(['id' => 'same'], 200),
        ]);

        $r = app(CalendarSync::class)->run($id);
        $this->assertSame(1, $r['tasks_moved']);
        $this->assertSame(1, $r['hearings_restored']);
        $this->assertSame('2026-10-09', substr((string) $t->fresh()->due_date, 0, 10), 'two-way for tasks');
        $this->assertSame('2026-10-07', substr((string) $h->fresh()->hearing_date, 0, 10), 'the court date stands');
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_contains($r->url(), '/events/evH') && $r['start']['date'] === '2026-10-07');
        $this->assertSame(1, DB::table('audits')->where('event', 'calendar_task_moved')->count());
    }

    public function test_a_refused_token_asks_for_reconnection_and_disconnect_cleans_up(): void
    {
        $this->hearing('2026-10-07');
        $id = $this->googleConnection();
        DB::table('calendar_connections')->update(['expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->assertNull(app(CalendarSync::class)->run($id));
        $this->assertSame('error', DB::table('calendar_connections')->value('state'));
        $this->actingAs($this->lawyer)->getJson('/api/v1/calendar/connections')->assertJsonPath('data.connections.0.state', 'error');

        DB::table('calendar_event_links')->insert(['calendar_connection_id' => $id, 'source_type' => 'hearing', 'source_id' => 1, 'external_id' => 'evH', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake(['www.googleapis.com/*' => Http::response(null, 204)]);
        $this->actingAs($this->userWithPermissions([]))->deleteJson("/api/v1/calendar/connections/{$id}")->assertNotFound();   // someone else's
        $this->actingAs($this->lawyer)->deleteJson("/api/v1/calendar/connections/{$id}")->assertOk();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), '/events/evH'));
        $this->assertSame(0, DB::table('calendar_connections')->count());
    }

    public function test_microsoft_events_are_created_through_graph(): void
    {
        config(['calendar.microsoft.client_id' => 'mid', 'calendar.microsoft.client_secret' => 'msecret']);
        Bus::fake([\App\Jobs\SyncCalendarConnection::class]);
        $this->hearing('2026-10-07', '09:30');
        $id = DB::table('calendar_connections')->insertGetId(['user_id' => $this->lawyer->id, 'provider' => 'microsoft', 'access_token' => Crypt::encryptString('at'),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        Http::fake([
            'graph.microsoft.com/v1.0/me/calendarView/delta*' => Http::response(['value' => [], '@odata.deltaLink' => 'https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=d1']),
            'graph.microsoft.com/v1.0/me/events' => Http::response(['id' => 'AAMk1'], 201),
        ]);
        $this->assertSame(1, app(CalendarSync::class)->run($id)['created']);
        Http::assertSent(fn ($r) => $r->method() === 'POST' && $r['start'] === ['dateTime' => '2026-10-07T06:30:00', 'timeZone' => 'UTC'] && $r['isAllDay'] === false);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/calendarView/delta?$deltatoken=d1', DB::table('calendar_connections')->value('sync_token'));
    }
}
