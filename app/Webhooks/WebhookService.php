<?php

namespace App\Webhooks;

use App\Tenancy\Keys\FirmCrypt;
use App\Entitlements\Entitlements;
use App\PublicApi\Resources;
use App\Security\SecurityPolicy;
use App\Sso\SsoException;
use App\Sso\SsrfGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * PLAT-11: webhook endpoints, event fan-out and delivery with retry/replay.
 *
 * Delivery is AT LEAST ONCE: receivers de-duplicate on the event id (`id`) and must not assume ordering. Each attempt is signed
 * (`X-Webhook-Signature: t=<unix>,v1=<hex hmac-sha256 of "<t>.<body>">`) with the endpoint's secret, which is shown once and stored encrypted.
 * URLs go through the same SSRF guard as SSO, at registration and again at every attempt (DNS can change).
 */
class WebhookService
{
    public function __construct(private Entitlements $entitlements)
    {
    }

    // ---- endpoints -------------------------------------------------------------------------------------------------

    public static function newSecret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    private function cleanEvents(array $events): array
    {
        $events = array_values(array_unique($events));
        if (! $events) {
            throw new WebhookException('Choose at least one event.');
        }
        if ($events !== ['*']) {
            $bad = array_diff($events, (array) config('public_api.events'));
            if ($bad) {
                throw new WebhookException('Unknown event(s): '.implode(', ', $bad));
            }
        }

        return $events;
    }

    private function checkUrl(string $url): void
    {
        try {
            SsrfGuard::check($url);
        } catch (SsoException $e) {
            throw new WebhookException('Webhook URL refused: '.str_replace('The identity provider', 'The', $e->getMessage()));
        }
    }

    /** @return array{endpoint:object,secret:string} the secret is returned once */
    public function create(string $url, array $events, ?string $description, ?int $actor): array
    {
        if (DB::table('webhook_endpoints')->count() >= (int) config('public_api.webhooks.max_endpoints')) {
            throw new WebhookException('Too many webhook endpoints.');
        }
        $this->checkUrl($url);
        $secret = self::newSecret();
        $id = DB::table('webhook_endpoints')->insertGetId([
            'url' => $url, 'description' => $description, 'secret' => FirmCrypt::encryptString($secret), 'events' => json_encode($this->cleanEvents($events)),
            'active' => true, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('webhook_created', 'webhook', $id, [], ['url' => $url, 'events' => $events]);

        return ['endpoint' => $this->find($id), 'secret' => $secret];
    }

    public function find(int $id): object
    {
        return DB::table('webhook_endpoints')->where('id', $id)->first() ?? throw new WebhookException('No such webhook.', 404);
    }

    public function update(int $id, array $d, ?int $actor): object
    {
        $e = $this->find($id);
        $set = ['updated_at' => now()];
        if (isset($d['url'])) {
            $this->checkUrl($d['url']);
            $set['url'] = $d['url'];
        }
        if (isset($d['events'])) {
            $set['events'] = json_encode($this->cleanEvents($d['events']));
        }
        if (array_key_exists('description', $d)) {
            $set['description'] = $d['description'];
        }
        if (isset($d['active'])) {
            $set['active'] = (bool) $d['active'];
            if ($d['active']) {
                $set += ['consecutive_dead' => 0, 'disabled_reason' => null];   // switching it back on starts clean
            }
        }
        DB::table('webhook_endpoints')->where('id', $id)->update($set);
        SecurityPolicy::audit('webhook_updated', 'webhook', $id, ['url' => $e->url], array_diff_key($set, ['updated_at' => 1]));

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $e = $this->find($id);
        DB::table('webhook_deliveries')->where('endpoint_id', $id)->delete();
        DB::table('webhook_endpoints')->where('id', $id)->delete();
        SecurityPolicy::audit('webhook_deleted', 'webhook', $id, ['url' => $e->url], []);
    }

    /** @return string the new secret, shown once. The old one stops working at once. */
    public function rotateSecret(int $id): string
    {
        $this->find($id);
        $secret = self::newSecret();
        DB::table('webhook_endpoints')->where('id', $id)->update(['secret' => FirmCrypt::encryptString($secret), 'updated_at' => now()]);
        SecurityPolicy::audit('webhook_secret_rotated', 'webhook', $id, [], []);

        return $secret;
    }

    // ---- events ----------------------------------------------------------------------------------------------------

    public function emit(string $event, array $object): int
    {
        if (! $this->entitlements->has('api_access')) {
            return 0;   // the plan does not include the integration surface
        }
        $n = 0;
        foreach (DB::table('webhook_endpoints')->where('active', true)->get() as $e) {
            $events = (array) json_decode((string) $e->events, true);
            if (in_array('*', $events, true) || in_array($event, $events, true)) {
                $this->enqueue($e->id, $event, $object);
                $n++;
            }
        }

        return $n;
    }

    private function enqueue(int $endpointId, string $event, array $object, ?string $eventId = null): int
    {
        $eventId ??= 'evt_'.Str::lower(Str::random(24));

        return DB::table('webhook_deliveries')->insertGetId([
            'endpoint_id' => $endpointId, 'event_id' => $eventId, 'event' => $event,
            'payload' => json_encode(['id' => $eventId, 'type' => $event, 'created' => now()->toIso8601String(), 'data' => ['object' => $object]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function ping(int $id): int
    {
        $this->find($id);

        return $this->enqueue($id, 'ping', ['message' => 'ping']);
    }

    /** Send the same event again (same event id), e.g. after the receiver was down for longer than the retry window. */
    public function replay(int $deliveryId): object
    {
        $d = DB::table('webhook_deliveries')->where('id', $deliveryId)->first() ?? throw new WebhookException('No such delivery.', 404);
        if (in_array($d->status, ['pending', 'sending', 'retry'], true)) {
            throw new WebhookException('That delivery is still in progress.', 409);
        }
        DB::table('webhook_deliveries')->where('id', $deliveryId)->update(['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'locked_until' => null, 'last_error' => null, 'updated_at' => now()]);
        SecurityPolicy::audit('webhook_replayed', 'webhook', (int) $d->endpoint_id, [], ['delivery' => $deliveryId, 'event_id' => $d->event_id]);

        return DB::table('webhook_deliveries')->where('id', $deliveryId)->first();
    }

    // ---- delivery --------------------------------------------------------------------------------------------------

    public static function signature(string $secret, string $body, int $ts): string
    {
        return "t={$ts},v1=".hash_hmac('sha256', "{$ts}.{$body}", $secret);
    }

    /** @return array{sent:int,delivered:int,retry:int,dead:int} */
    public function runDue(int $limit = 100, ?\Carbon\CarbonInterface $now = null): array
    {
        $now ??= now();
        $r = ['sent' => 0, 'delivered' => 0, 'retry' => 0, 'dead' => 0];
        $due = DB::table('webhook_deliveries')->where(function ($q) use ($now) {
            $q->whereIn('status', ['pending', 'retry'])->where('next_attempt_at', '<=', $now);
        })->orWhere(function ($q) use ($now) {
            $q->where('status', 'sending')->where('locked_until', '<', $now);   // a worker died mid-send
        })->orderBy('id')->limit($limit)->pluck('id');

        foreach ($due as $id) {
            $d = DB::table('webhook_deliveries')->where('id', $id)->first();
            // claim: only one runner may send a given delivery
            $claimed = DB::table('webhook_deliveries')->where('id', $id)->where('status', $d->status)->where('attempts', $d->attempts)
                ->update(['status' => 'sending', 'locked_until' => $now->copy()->addSeconds((int) config('public_api.webhooks.lock_seconds')), 'updated_at' => $now]);
            if (! $claimed) {
                continue;
            }
            $r['sent']++;
            $r[$this->attempt($d, $now)]++;
        }

        return $r;
    }

    /** @return string delivered | retry | dead */
    private function attempt(object $d, \Carbon\CarbonInterface $now): string
    {
        $e = DB::table('webhook_endpoints')->where('id', $d->endpoint_id)->first();
        $attempts = (int) $d->attempts + 1;
        if (! $e || ! $e->active) {
            return $this->fail($d, $attempts, null, $e ? 'endpoint is switched off' : 'endpoint was deleted', $now, final: true);
        }
        $code = null;
        try {
            $this->checkUrl($e->url);
            $body = $d->payload;
            $ts = $now->getTimestamp();
            $resp = Http::timeout((int) config('public_api.webhooks.timeout_seconds'))->withoutRedirecting()
                ->withHeaders(['X-Webhook-Id' => $d->event_id, 'X-Webhook-Event' => $d->event, 'X-Webhook-Timestamp' => (string) $ts,
                    'X-Webhook-Signature' => self::signature(FirmCrypt::decryptString($e->secret), $body, $ts), 'User-Agent' => 'LawFirm-Webhooks/1'])
                ->withBody($body, 'application/json')->post($e->url);
            $code = $resp->status();
            if ($resp->successful()) {
                DB::table('webhook_deliveries')->where('id', $d->id)->update(['status' => 'delivered', 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => null, 'delivered_at' => $now, 'locked_until' => null, 'updated_at' => $now]);
                DB::table('webhook_endpoints')->where('id', $e->id)->update(['consecutive_dead' => 0]);

                return 'delivered';
            }
            $error = "HTTP {$code}";
        } catch (\Throwable $ex) {
            $error = Str::limit(preg_replace('/\s+/', ' ', $ex->getMessage()), 300, '');
        }

        return $this->fail($d, $attempts, $code, $error, $now);
    }

    private function fail(object $d, int $attempts, ?int $code, string $error, \Carbon\CarbonInterface $now, bool $final = false): string
    {
        $max = (int) config('public_api.webhooks.max_attempts');
        $dead = $final || $attempts >= $max;
        $backoff = (array) config('public_api.webhooks.backoff_minutes');
        $next = $dead ? null : $now->copy()->addMinutes((int) ($backoff[min($attempts, count($backoff)) - 1] ?? end($backoff)));
        DB::table('webhook_deliveries')->where('id', $d->id)->update([
            'status' => $dead ? 'dead' : 'retry', 'attempts' => $attempts, 'last_status_code' => $code, 'last_error' => Str::limit($error, 500, ''),
            'next_attempt_at' => $next, 'locked_until' => null, 'updated_at' => $now,
        ]);
        if ($dead) {
            $n = DB::table('webhook_endpoints')->where('id', $d->endpoint_id)->increment('consecutive_dead');
            $row = DB::table('webhook_endpoints')->where('id', $d->endpoint_id)->first();
            if ($row && $row->active && (int) $row->consecutive_dead >= (int) config('public_api.webhooks.disable_after_dead')) {
                DB::table('webhook_endpoints')->where('id', $row->id)->update(['active' => false, 'disabled_reason' => 'Switched off after repeated failed deliveries.', 'updated_at' => $now]);
            }
        }

        return $dead ? 'dead' : 'retry';
    }

    public function prune(): int
    {
        return DB::table('webhook_deliveries')->whereIn('status', ['delivered', 'dead'])->where('updated_at', '<', now()->subDays((int) config('public_api.webhooks.keep_days')))->delete();
    }

    // ---- model hooks -----------------------------------------------------------------------------------------------

    public static function register(): void
    {
        $map = [
            \App\Models\Client::class => ['client', fn ($m) => Resources::client($m)],
            \App\Models\Cases::class => ['case', fn ($m) => Resources::matter($m)],
            \App\Models\Hearing::class => ['hearing', fn ($m) => Resources::hearing($m)],
        ];
        foreach ($map as $class => [$name, $render]) {
            foreach (['created', 'updated', 'deleted'] as $when) {
                $class::$when(function ($m) use ($name, $when, $render) {
                    try {
                        app(self::class)->emit("{$name}.{$when}", $render($m));
                    } catch (\Throwable $e) {
                        report($e);   // an integration problem must never break saving a matter
                    }
                });
            }
        }
    }
}
