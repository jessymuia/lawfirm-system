<?php

namespace App\Calendar\Providers;

use App\Secrets\IntegrationCredentials;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** FUN-16: Google Calendar API v3 (events scope only). */
class GoogleCalendar implements CalendarProvider
{
    private const API = 'https://www.googleapis.com/calendar/v3/calendars/';

    public function __construct(private IntegrationCredentials $creds) {}

    public function configured(): bool
    {
        return (bool) ($this->creds->resolve('google_calendar', 'client_id') && $this->creds->resolve('google_calendar', 'client_secret'));
    }

    public function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->creds->resolve('google_calendar', 'client_id'), 'redirect_uri' => config('calendar.oauth_redirect'),
            'response_type' => 'code', 'scope' => 'openid email https://www.googleapis.com/auth/calendar.events',
            'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true', 'state' => $state,
        ]);
    }

    public function exchange(string $code): array
    {
        $r = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('calendar.oauth_redirect')]);
        // the id token comes straight from Google's token endpoint over TLS, so its claims can be read without re-verifying
        $claims = json_decode((string) base64_decode(strtr(explode('.', (string) ($r['id_token'] ?? '..'))[1] ?? '', '-_', '+/')), true) ?: [];

        return ['access_token' => $r['access_token'], 'refresh_token' => $r['refresh_token'] ?? null, 'expires_in' => (int) ($r['expires_in'] ?? 3600),
            'email' => $claims['email'] ?? null];
    }

    public function refresh(string $refreshToken): array
    {
        $r = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

        return ['access_token' => $r['access_token'], 'refresh_token' => $r['refresh_token'] ?? null, 'expires_in' => (int) ($r['expires_in'] ?? 3600)];
    }

    public function upsert(string $token, string $calendarId, array $item, ?string $externalId): string
    {
        $tz = (string) config('calendar.timezone');
        $body = [
            'summary' => $item['title'], 'description' => $item['description'], 'location' => $item['location'],
            'start' => $item['start'] ? ['dateTime' => $item['start']->toIso8601String(), 'timeZone' => $tz] : ['date' => $item['date']],
            'end' => $item['start'] ? ['dateTime' => $item['end']->toIso8601String(), 'timeZone' => $tz]
                : ['date' => CarbonImmutable::parse($item['date'])->addDay()->toDateString()],
            'transparency' => $item['start'] ? 'opaque' : 'transparent',
            'source' => ['title' => config('app.name'), 'url' => $item['url']],
            'extendedProperties' => ['private' => ['lawfirm_uid' => $item['uid']]],
        ];
        $url = self::API.rawurlencode($calendarId).'/events';
        if ($externalId) {
            $r = Http::withToken($token)->put($url.'/'.rawurlencode($externalId), $body);
            if (! in_array($r->status(), [404, 410], true)) {
                return (string) $this->ok($r)->json('id');
            }
            // deleted in the calendar: the court date still stands, so it is put back as a new event
        }

        return (string) $this->ok(Http::withToken($token)->post($url, $body))->json('id');
    }

    public function delete(string $token, string $calendarId, string $externalId): void
    {
        $r = Http::withToken($token)->delete(self::API.rawurlencode($calendarId).'/events/'.rawurlencode($externalId));
        in_array($r->status(), [404, 410], true) || $this->ok($r);
    }

    public function changes(string $token, string $calendarId, ?string $cursor): array
    {
        $changes = [];
        $base = ($cursor ? ['syncToken' => $cursor] : ['timeMin' => now()->subDays((int) config('calendar.window.past_days'))->toIso8601String()])
            + ['showDeleted' => 'true', 'maxResults' => 250, 'singleEvents' => 'true'];
        $page = null;
        do {
            $r = Http::withToken($token)->get(self::API.rawurlencode($calendarId).'/events', $base + ($page ? ['pageToken' => $page] : []));
            if ($r->status() === 410) {
                return $this->changes($token, $calendarId, null);   // the cursor expired: start a new one
            }
            $data = $this->ok($r)->json();
            foreach ($data['items'] ?? [] as $e) {
                $start = $e['start']['date'] ?? $e['start']['dateTime'] ?? null;
                $changes[] = ['id' => (string) $e['id'], 'cancelled' => ($e['status'] ?? '') === 'cancelled', 'date' => $start ? substr((string) $start, 0, 10) : null];
            }
            $page = $data['nextPageToken'] ?? null;
        } while ($page);

        return ['changes' => $changes, 'cursor' => $data['nextSyncToken'] ?? $cursor];
    }

    private function token(array $params): array
    {
        $r = Http::asForm()->post('https://oauth2.googleapis.com/token', $params + [
            'client_id' => $this->creds->resolve('google_calendar', 'client_id'), 'client_secret' => $this->creds->resolve('google_calendar', 'client_secret'),
        ]);
        if (in_array($r->status(), [400, 401], true)) {
            throw new CalendarAuthException('Google refused the connection ('.($r->json('error') ?? $r->status()).'). Connect the calendar again.');
        }

        return $this->ok($r)->json();
    }

    private function ok(Response $r): Response
    {
        if ($r->status() === 401) {
            throw new CalendarAuthException('Google no longer accepts this connection. Connect the calendar again.');
        }
        if ($r->failed()) {
            throw new \RuntimeException('Google Calendar answered '.$r->status().': '.mb_strimwidth((string) ($r->json('error.message') ?? $r->body()), 0, 200, '…'));
        }

        return $r;
    }
}
