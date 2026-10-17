<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Hearing;
use App\Models\HearingType;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** FUN-19: the installable web app and the hearing-day view. */
class MobileTodayTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private User $lawyer;

    private Cases $matter;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake([\App\Jobs\SyncCalendarConnection::class]);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-05 07:30:00', 'Africa/Nairobi'));
        $this->lawyer = $this->userWithPermissions([P::LIST_HEARINGS, P::UPDATE_HEARINGS, P::CREATE_HEARINGS, P::LIST_CASES]);
        $client = Client::factory()->create(['name' => 'Akinyi Odhiambo', 'phone_number' => '0722000111']);
        $this->matter = Cases::factory()->create(['case_number' => 'CMCC 88/2026', 'assigned_to' => $this->lawyer->id, 'client_id' => $client->id]);
    }

    private function hearing(string $date, ?string $time = null, ?Cases $m = null): Hearing
    {
        return Hearing::create(['case_id' => ($m ?? $this->matter)->id, 'court_id' => ($m ?? $this->matter)->court_id, 'hearing_date' => $date,
            'hearing_type' => HearingType::factory()->create(['name' => 'Mention'])->id, 'notes' => 'n', 'start_time' => $time, 'courtroom' => 'Court 2']);
    }

    public function test_the_app_is_installable_and_names_the_firm(): void
    {
        config(['app.name' => 'Wairimu & Partners Advocates']);
        $m = $this->get('/manifest.webmanifest')->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();
        $this->assertSame(['Wairimu & Partners Advocates', 'Wairimu', 'standalone', '/today?source=pwa'], [$m['name'], $m['short_name'], $m['display'], $m['start_url']]);
        foreach ($m['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
            [$w, $h] = getimagesize(public_path(ltrim($icon['src'], '/')));
            $this->assertSame($icon['sizes'], "{$w}x{$h}");
        }
        // the app shell links it (read from the template: rendering it needs the built front-end, which backend CI does not build)
        $this->assertStringContainsString('<link rel="manifest" href="/manifest.webmanifest">', (string) file_get_contents(resource_path('views/app.blade.php')));

        // the service worker keeps only the hearing-day data offline, and never touches the client-facing pages
        $sw = (string) file_get_contents(public_path('sw.js'));
        $this->assertStringContainsString("const OFFLINE_DATA = /^\\/api\\/v1\\/(today|court-diary)(\\?|$)/;", $sw);
        foreach (['api', 'portal', 'pay', 'sign', 'calendar', 'sanctum'] as $path) {
            $this->assertMatchesRegularExpression('/NOT_OURS = .*\b'.$path.'\b/', $sw);
        }
        $this->assertStringContainsString("'signed-out'", $sw);
        $this->assertFileExists(public_path('offline.html'));
    }

    public function test_today_shows_my_hearings_today_and_tomorrow_and_my_tasks_due(): void
    {
        $this->hearing('2026-10-05', '09:00');
        $this->hearing('2026-10-05');
        $this->hearing('2026-10-06', '14:30');
        $this->hearing('2026-10-08');                                                      // later
        $this->hearing('2026-10-05', '08:00', Cases::factory()->create(['case_number' => 'SOMEONE-ELSES']));
        Task::create(['title' => 'File submissions', 'description' => 'x', 'assigned_to' => $this->lawyer->id, 'due_date' => '2026-10-04', 'priority' => 'high', 'task_status' => 'pending']);
        Task::create(['title' => 'Later', 'description' => 'x', 'assigned_to' => $this->lawyer->id, 'due_date' => '2026-10-20', 'priority' => 'low', 'task_status' => 'pending']);

        $d = $this->actingAs($this->lawyer)->getJson('/api/v1/today')->assertOk()->json('data');
        $this->assertSame('2026-10-05', $d['date']);
        $this->assertSame([['today', '09:00'], ['today', null], ['tomorrow', '14:30']], array_map(fn ($h) => [$h['day'], $h['start_time']], $d['hearings']));
        $this->assertSame(['CMCC 88/2026', 'Akinyi Odhiambo', '0722000111', 'Court 2'],
            [$d['hearings'][0]['case_number'], $d['hearings'][0]['client'], $d['hearings'][0]['client_phone'], $d['hearings'][0]['courtroom']]);
        $this->assertSame(['File submissions'], array_column($d['tasks'], 'title'));
        $this->assertTrue($d['can_record']);

        // without the hearings permission: tasks only
        $this->actingAs($this->userWithPermissions([]))->getJson('/api/v1/today')->assertOk()->assertJsonCount(0, 'data.hearings');
    }

    public function test_an_outcome_and_the_next_date_are_recorded_in_one_step(): void
    {
        $h = $this->hearing('2026-10-05', '09:00');
        $id = $this->actingAs($this->lawyer)->postJson("/api/v1/hearings/{$h->id}/outcome", ['outcome' => 'Adjourned: plaintiff to file a further affidavit.', 'next_date' => '2026-11-12', 'next_time' => '10:00'])
            ->assertOk()->assertJsonPath('message', 'Outcome and next date saved.')->json('data.next_hearing_id');
        $this->assertSame('Adjourned: plaintiff to file a further affidavit.', $h->fresh()->outcome);
        $next = Hearing::find($id);
        $this->assertSame(['2026-11-12', $h->court_id, $h->hearing_type, 'Court 2'], [substr((string) $next->hearing_date, 0, 10), $next->court_id, $next->hearing_type, $next->courtroom]);
        $this->assertSame('10:00', substr((string) $next->start_time, 0, 5));

        $this->postJson("/api/v1/hearings/{$h->id}/outcome", ['outcome' => 'x', 'next_date' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('next_date');
        $this->actingAs($this->userWithPermissions([P::UPDATE_HEARINGS]))->postJson("/api/v1/hearings/{$h->id}/outcome", ['outcome' => 'x', 'next_date' => '2026-11-20'])->assertForbidden();
        $this->actingAs($this->userWithPermissions([P::LIST_HEARINGS]))->postJson("/api/v1/hearings/{$h->id}/outcome", ['outcome' => 'x'])->assertForbidden();

        config(['matters.access' => 'restricted']);
        $outsider = $this->userWithPermissions([P::UPDATE_HEARINGS]);
        $this->actingAs($outsider)->postJson("/api/v1/hearings/{$h->id}/outcome", ['outcome' => 'x'])->assertNotFound();
        $this->assertSame(1, DB::table('audits')->where('event', 'hearing_outcome_recorded')->count());
    }
}
