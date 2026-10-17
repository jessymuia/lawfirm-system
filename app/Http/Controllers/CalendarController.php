<?php

namespace App\Http\Controllers;

use App\Calendar\CalendarItems;
use App\Calendar\CalendarSync;
use App\Calendar\Ics;
use App\Constants\ModulePermissions;
use App\Http\Requests\Calendar\DiaryRequest;
use App\Http\Requests\Calendar\FeedRequest;
use App\Jobs\SyncCalendarConnection;
use App\Matters\MatterAccess;
use App\Models\User;
use App\Security\SecurityPolicy;
use App\Tenancy\Keys\FirmCrypt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FUN-16: the court diary, iCalendar subscription feeds, and Google / Microsoft calendar connections. Feeds and
 * connections belong to the signed-in person and show only what that person may see.
 */
class CalendarController extends Controller
{
    public function __construct(private CalendarItems $items) {}

    // ---- court diary ----

    /** Hearings day by day across the firm (or one court / one lawyer), within the matters the viewer may open. */
    public function diary(DiaryRequest $r): JsonResponse
    {
        $from = $r->validated('from') ?? now()->toDateString();
        $to = $r->validated('to') ?? now()->addDays(13)->toDateString();
        $rows = DB::table('hearings')
            ->join('cases', 'cases.id', '=', 'hearings.case_id')
            ->leftJoin('clients', 'clients.id', '=', 'cases.client_id')
            ->leftJoin('courts', 'courts.id', '=', 'hearings.court_id')
            ->leftJoin('hearing_types', 'hearing_types.id', '=', 'hearings.hearing_type')
            ->leftJoin('users', 'users.id', '=', 'cases.assigned_to')
            ->whereIn('hearings.case_id', MatterAccess::visibleCaseIds(Auth::user()))
            ->whereNull('hearings.deleted_at')->whereNull('cases.deleted_at')
            ->whereBetween('hearings.hearing_date', [$from, $to])
            ->when($r->validated('court_id'), fn ($q, $v) => $q->where('hearings.court_id', $v))
            ->when($r->validated('assigned_to'), fn ($q, $v) => $q->where('cases.assigned_to', $v))
            ->orderBy('hearings.hearing_date')->orderByRaw('hearings.start_time is null')->orderBy('hearings.start_time')->orderBy('courts.name')
            ->get(['hearings.id', 'hearings.case_id', 'hearings.hearing_date', 'hearings.start_time', 'hearings.duration_minutes', 'hearings.courtroom',
                'hearings.judicial_officer', 'hearings.efiling_reference', 'cases.case_number', 'clients.name as client',
                DB::raw('coalesce(courts.alias, courts.name) as court'), DB::raw('coalesce(hearing_types.alias, hearing_types.name) as type'), 'users.name as lawyer'])
            ->map(fn ($h) => array_merge((array) $h, ['start_time' => $h->start_time ? substr((string) $h->start_time, 0, 5) : null]));   // MySQL TIME is HH:MM:SS

        return $this->response(true, 'success', ['from' => $from, 'to' => $to, 'days' => $rows->groupBy('hearing_date')->map->values()], 200);
    }

    // ---- subscription feeds ----

    public function feeds(): JsonResponse
    {
        return $this->response(true, 'success', DB::table('calendar_feeds')->where('user_id', Auth::id())->whereNull('revoked_at')
            ->orderByDesc('id')->get(['id', 'scope', 'last_used_at', 'created_at']), 200);
    }

    /** A new secret feed link (shown once). The firm-wide court diary feed needs the hearings permission. */
    public function createFeed(FeedRequest $r): JsonResponse
    {
        $scope = $r->validated('scope');
        if ($scope === 'diary' && ! Auth::user()->checkPermissionTo(ModulePermissions::LIST_HEARINGS)) {
            abort(403);
        }
        $token = Str::random(48);
        $id = DB::table('calendar_feeds')->insertGetId(['user_id' => Auth::id(), 'scope' => $scope, 'token_hash' => hash('sha256', $token),
            'created_at' => now(), 'updated_at' => now()]);
        SecurityPolicy::audit('calendar_feed_created', 'calendar_feed', $id, [], ['scope' => $scope]);

        return $this->response(true, 'Feed created. Copy the link now: it is shown only once.', ['id' => $id, 'scope' => $scope,
            'url' => $r->getSchemeAndHttpHost().'/calendar/'.$token.'.ics'], 201);
    }

    public function revokeFeed(int $feed): JsonResponse
    {
        $n = DB::table('calendar_feeds')->where('id', $feed)->where('user_id', Auth::id())->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        abort_if($n === 0, 404);
        SecurityPolicy::audit('calendar_feed_revoked', 'calendar_feed', $feed, [], []);

        return $this->response(true, 'Feed link turned off.', null, 200);
    }

    /** The feed itself (public: the secret link is the credential). Shows what its owner may see today, not when it was made. */
    public function ics(string $token)
    {
        $feed = DB::table('calendar_feeds')->where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
        $user = $feed ? User::find($feed->user_id) : null;   // a removed person's links stop working
        abort_if(! $user, 404);
        if ($feed->scope === 'diary' && ! $user->checkPermissionTo(ModulePermissions::LIST_HEARINGS)) {
            abort(404);
        }
        DB::table('calendar_feeds')->where('id', $feed->id)->update(['last_used_at' => now()]);
        $name = config('app.name').' – '.($feed->scope === 'diary' ? 'Court diary' : $user->name);

        return response(app(Ics::class)->render($name, $this->items->for($user, $feed->scope)), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'inline; filename="calendar.ics"',
            'Cache-Control' => 'private, max-age=300', 'X-Robots-Tag' => 'noindex',
        ]);
    }

    // ---- Google / Microsoft connections ----

    public function connections(CalendarSync $sync): JsonResponse
    {
        return $this->response(true, 'success', [
            'available' => ['google' => $sync->provider('google')->configured(), 'microsoft' => $sync->provider('microsoft')->configured()],
            'connections' => DB::table('calendar_connections')->where('user_id', Auth::id())
                ->get(['id', 'provider', 'account_email', 'state', 'error', 'last_synced_at', 'created_at']),
        ], 200);
    }

    /**
     * Where to send the browser to allow access. The state carries the person, the provider, the firm's host and an expiry,
     * encrypted with the deployment key so it cannot be forged or altered. The provider's code is single use.
     */
    public function connect(Request $r, CalendarSync $sync, string $provider): JsonResponse
    {
        $p = $sync->provider($provider);
        if (! $p->configured()) {
            return $this->response(false, 'This calendar provider is not set up for the firm yet.', null, 422);
        }
        $state = rtrim(strtr(base64_encode(Crypt::encryptString(json_encode([
            'h' => $r->getSchemeAndHttpHost(), 'u' => Auth::id(), 'p' => $provider, 'x' => now()->addMinutes(10)->timestamp, 'n' => Str::random(16),
        ]))), '+/', '-_'), '=');

        return $this->response(true, 'success', ['url' => $p->authUrl($state)], 200);
    }

    /**
     * The provider sends the browser back here. A multi-firm deployment registers one address on a shared host, so the
     * browser is first handed on to the firm's own host named in the state; there the code is exchanged.
     */
    public function callback(Request $r, CalendarSync $sync)
    {
        try {
            $state = json_decode(Crypt::decryptString(base64_decode(strtr((string) $r->query('state'), '-_', '+/'))), true);
        } catch (\Throwable) {
            $state = null;
        }
        if (! is_array($state) || ! preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?$#', (string) ($state['h'] ?? '')) || (int) ($state['x'] ?? 0) < now()->timestamp) {
            return redirect('/calendar-sync?calendar=expired');
        }
        if ($state['h'] !== $r->getSchemeAndHttpHost()) {
            return redirect()->away($state['h'].'/calendar/oauth/callback?'.http_build_query($r->only(['code', 'state', 'error'])));
        }
        // replaying a state is refused (the provider refuses a reused code as well)
        if (! Cache::add('calendar-oauth-used:'.$state['n'], 1, now()->addMinutes(15))) {
            return redirect('/calendar-sync?calendar=expired');
        }
        $back = ['user_id' => (int) $state['u'], 'provider' => (string) $state['p']];
        if (! in_array($back['provider'], ['google', 'microsoft'], true) || ! User::find($back['user_id'])) {
            return redirect('/calendar-sync?calendar=expired');
        }
        if ($r->query('error') || ! $r->query('code')) {
            return redirect('/calendar-sync?calendar=declined');
        }
        try {
            $t = $sync->provider($back['provider'])->exchange((string) $r->query('code'));
        } catch (\RuntimeException $e) {
            report($e);

            return redirect('/calendar-sync?calendar=failed');
        }
        DB::table('calendar_connections')->updateOrInsert(['user_id' => $back['user_id'], 'provider' => $back['provider']], [
            'account_email' => $t['email'], 'access_token' => FirmCrypt::encryptString($t['access_token']),
            'refresh_token' => $t['refresh_token'] ? FirmCrypt::encryptString($t['refresh_token']) : null,
            'expires_at' => now()->addSeconds($t['expires_in']), 'sync_token' => null, 'state' => 'active', 'error' => null,
            'updated_at' => now(), 'created_at' => now(),
        ]);
        $id = (int) DB::table('calendar_connections')->where('user_id', $back['user_id'])->where('provider', $back['provider'])->value('id');
        DB::table('calendar_event_links')->where('calendar_connection_id', $id)->delete();   // a (re)connection starts clean
        SecurityPolicy::audit('calendar_connected', 'calendar_connection', $id, [], ['provider' => $back['provider'], 'user_id' => $back['user_id']]);
        SyncCalendarConnection::dispatch($id);

        return redirect('/calendar-sync?calendar=connected');
    }

    public function sync(int $connection): JsonResponse
    {
        $c = DB::table('calendar_connections')->where('id', $connection)->where('user_id', Auth::id())->first();
        abort_if(! $c, 404);
        DB::table('calendar_connections')->where('id', $c->id)->where('state', 'error')->update(['state' => 'active', 'error' => null]);
        SyncCalendarConnection::dispatch((int) $c->id);

        return $this->response(true, 'Syncing now.', null, 202);
    }

    /** Disconnect: the events this system put in the calendar are removed, then the tokens are forgotten. */
    public function disconnect(int $connection, CalendarSync $sync): JsonResponse
    {
        $c = DB::table('calendar_connections')->where('id', $connection)->where('user_id', Auth::id())->first();
        abort_if(! $c, 404);
        try {
            $p = $sync->provider($c->provider);
            $token = FirmCrypt::decryptString($c->access_token);
            foreach (DB::table('calendar_event_links')->where('calendar_connection_id', $c->id)->pluck('external_id') as $ext) {
                $p->delete($token, $c->calendar_id, $ext);
            }
        } catch (\Throwable $e) {
            report($e);   // an expired token must not keep the person connected
        }
        DB::table('calendar_connections')->where('id', $c->id)->delete();
        SecurityPolicy::audit('calendar_disconnected', 'calendar_connection', (int) $c->id, [], ['provider' => $c->provider]);

        return $this->response(true, 'Calendar disconnected.', null, 200);
    }
}
