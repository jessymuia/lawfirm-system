<?php

namespace App\Calendar\Providers;

use App\Secrets\IntegrationCredentials;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** FUN-16: Microsoft 365 / Outlook calendar through Microsoft Graph (the person's default calendar). */
class MicrosoftCalendar implements CalendarProvider
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private const SCOPES = 'offline_access openid email User.Read Calendars.ReadWrite';

    public function __construct(private IntegrationCredentials $creds) {}

    public function configured(): bool
    {
        return (bool) ($this->creds->resolve('microsoft_calendar', 'client_id') && $this->creds->resolve('microsoft_calendar', 'client_secret'));
    }

    public function authUrl(string $state): string
    {
        return $this->login('authorize').'?'.http_build_query([
            'client_id' => $this->creds->resolve('microsoft_calendar', 'client_id'), 'response_type' => 'code', 'response_mode' => 'query',
            'redirect_uri' => config('calendar.oauth_redirect'), 'scope' => self::SCOPES, 'state' => $state, 'prompt' => 'select_account',
        ]);
    }

    public function exchange(string $code): array
    {
        $r = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => config('calendar.oauth_redirect')]);
        $me = Http::withToken($r['access_token'])->get(self::GRAPH.'/me', ['$select' => 'mail,userPrincipalName']);

        return ['access_token' => $r['access_token'], 'refresh_token' => $r['refresh_token'] ?? null, 'expires_in' => (int) ($r['expires_in'] ?? 3600),
            'email' => $me->successful() ? ($me->json('mail') ?: $me->json('userPrincipalName')) : null];
    }

    public function refresh(string $refreshToken): array
    {
        $r = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);

        return ['access_token' => $r['access_token'], 'refresh_token' => $r['refresh_token'] ?? null, 'expires_in' => (int) ($r['expires_in'] ?? 3600)];
    }

    public function upsert(string $token, string $calendarId, array $item, ?string $externalId): string
    {
        $allDay = ! $item['start'];
        $day = \Carbon\CarbonImmutable::parse($item['date']);
        $body = [
            'subject' => $item['title'], 'body' => ['contentType' => 'text', 'content' => $item['description']],
            'location' => ['displayName' => (string) $item['location']], 'isAllDay' => $allDay, 'showAs' => $allDay ? 'free' : 'busy',
            'start' => ['dateTime' => $allDay ? $day->format('Y-m-d\T00:00:00') : $item['start']->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => $allDay ? (string) config('calendar.timezone') : 'UTC'],
            'end' => ['dateTime' => $allDay ? $day->addDay()->format('Y-m-d\T00:00:00') : $item['end']->utc()->format('Y-m-d\TH:i:s'), 'timeZone' => $allDay ? (string) config('calendar.timezone') : 'UTC'],
            'isReminderOn' => ! $allDay,
        ];
        if ($externalId) {
            $r = Http::withToken($token)->patch(self::GRAPH.'/me/events/'.rawurlencode($externalId), $body);
            if ($r->status() !== 404) {
                return (string) $this->ok($r)->json('id');
            }
        }

        return (string) $this->ok(Http::withToken($token)->post(self::GRAPH.'/me/events', $body))->json('id');
    }

    public function delete(string $token, string $calendarId, string $externalId): void
    {
        $r = Http::withToken($token)->delete(self::GRAPH.'/me/events/'.rawurlencode($externalId));
        $r->status() === 404 || $this->ok($r);
    }

    public function changes(string $token, string $calendarId, ?string $cursor): array
    {
        $changes = [];
        $url = $cursor ?: self::GRAPH.'/me/calendarView/delta?'.http_build_query([
            'startDateTime' => now()->subDays((int) config('calendar.window.past_days'))->utc()->format('Y-m-d\TH:i:s\Z'),
            'endDateTime' => now()->addDays((int) config('calendar.window.future_days'))->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
        // Graph's delta links are absolute URLs on graph.microsoft.com; anything else is not followed
        if (! str_starts_with($url, self::GRAPH.'/')) {
            $url = self::GRAPH.'/me/calendarView/delta';
        }
        do {
            // dates come back in the firm's zone
            $r = Http::withToken($token)->withHeaders(['Prefer' => 'odata.maxpagesize=200, outlook.timezone="'.config('calendar.timezone').'"'])->get($url);
            if (in_array($r->status(), [410], true)) {
                return $this->changes($token, $calendarId, null);
            }
            $data = $this->ok($r)->json();
            foreach ($data['value'] ?? [] as $e) {
                $changes[] = ['id' => (string) $e['id'], 'cancelled' => isset($e['@removed']) || ! empty($e['isCancelled']),
                    'date' => isset($e['start']['dateTime']) ? substr((string) $e['start']['dateTime'], 0, 10) : null];
            }
            $url = $data['@odata.nextLink'] ?? null;
        } while ($url && str_starts_with($url, self::GRAPH.'/'));

        return ['changes' => $changes, 'cursor' => $data['@odata.deltaLink'] ?? $cursor];
    }

    private function login(string $what): string
    {
        $tenant = preg_replace('/[^A-Za-z0-9.-]/', '', (string) ($this->creds->resolve('microsoft_calendar', 'tenant') ?: 'common'));

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$what}";
    }

    private function token(array $params): array
    {
        $r = Http::asForm()->post($this->login('token'), $params + [
            'client_id' => $this->creds->resolve('microsoft_calendar', 'client_id'), 'client_secret' => $this->creds->resolve('microsoft_calendar', 'client_secret'),
            'scope' => self::SCOPES,
        ]);
        if (in_array($r->status(), [400, 401], true)) {
            throw new CalendarAuthException('Microsoft refused the connection ('.($r->json('error') ?? $r->status()).'). Connect the calendar again.');
        }

        return $this->ok($r)->json();
    }

    private function ok(Response $r): Response
    {
        if ($r->status() === 401) {
            throw new CalendarAuthException('Microsoft no longer accepts this connection. Connect the calendar again.');
        }
        if ($r->failed()) {
            throw new \RuntimeException('Microsoft Graph answered '.$r->status().': '.mb_strimwidth((string) ($r->json('error.message') ?? $r->body()), 0, 200, '…'));
        }

        return $r;
    }
}
