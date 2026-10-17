<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApiKeyAuth;
use App\Http\Requests\Integrations\CreateHookRequest;
use App\Http\Requests\Integrations\CreateKeyRequest;
use App\Http\Requests\Integrations\UpdateHookRequest;
use App\Models\User;
use App\Security\SecurityPolicy;
use App\Webhooks\WebhookException;
use App\Webhooks\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** PLAT-11: a firm administrator manages API keys and webhooks. Secrets are shown once, at creation. */
class IntegrationsController extends Controller
{
    public function __construct(private WebhookService $webhooks) {}

    // ---- API keys --------------------------------------------------------------------------------------------------

    private function keyView(object $k): array
    {
        return ['id' => $k->id, 'name' => $k->name, 'prefix' => 'lfk_'.$k->prefix, 'scopes' => json_decode((string) $k->scopes, true), 'user_id' => $k->user_id,
            'rate_limit' => $k->rate_limit, 'expires_at' => $k->expires_at, 'revoked_at' => $k->revoked_at, 'last_used_at' => $k->last_used_at, 'created_at' => $k->created_at];
    }

    public function keys()
    {
        return response()->json(['data' => DB::table('api_keys')->orderByDesc('id')->get()->map(fn ($k) => $this->keyView($k))]);
    }

    public function createKey(CreateKeyRequest $r)
    {
        $d = $r->validated();
        // the key acts as a person: by default the creator. Acting as someone else needs the same permission, and the person must be a real firm user.
        $userId = (int) ($d['user_id'] ?? $r->user()->id);
        if (! User::whereKey($userId)->whereNull('platform_staff_id')->exists()) {
            throw new WebhookException('No such user.');
        }
        $g = ApiKeyAuth::generate();
        $id = DB::table('api_keys')->insertGetId([
            'name' => $d['name'], 'prefix' => $g['prefix'], 'key_hash' => $g['hash'], 'scopes' => json_encode(array_values(array_unique($d['scopes']))), 'user_id' => $userId,
            'rate_limit' => $d['rate_limit'] ?? null, 'expires_at' => $d['expires_at'] ?? null, 'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        SecurityPolicy::audit('api_key_created', 'api_key', $id, [], ['name' => $d['name'], 'scopes' => $d['scopes'], 'user_id' => $userId]);

        return response()->json(['data' => $this->keyView(DB::table('api_keys')->where('id', $id)->first()) + ['key' => $g['token']], 'notice' => 'Copy the key now: it is not shown again.'], 201);
    }

    public function revokeKey(Request $r, int $id)
    {
        $k = DB::table('api_keys')->where('id', $id)->first() ?? abort(404);
        if (! $k->revoked_at) {
            DB::table('api_keys')->where('id', $id)->update(['revoked_at' => now(), 'revoked_by' => $r->user()->id, 'updated_at' => now()]);
            SecurityPolicy::audit('api_key_revoked', 'api_key', $id, ['name' => $k->name], []);
        }

        return response()->json(['data' => $this->keyView(DB::table('api_keys')->where('id', $id)->first())]);
    }

    // ---- webhooks --------------------------------------------------------------------------------------------------

    private function hookView(object $e): array
    {
        return ['id' => $e->id, 'url' => $e->url, 'description' => $e->description, 'events' => json_decode((string) $e->events, true), 'active' => (bool) $e->active,
            'consecutive_dead' => $e->consecutive_dead, 'disabled_reason' => $e->disabled_reason, 'created_at' => $e->created_at];
    }

    public function hooks()
    {
        return response()->json(['data' => DB::table('webhook_endpoints')->orderBy('id')->get()->map(fn ($e) => $this->hookView($e)), 'events' => config('public_api.events')]);
    }

    public function createHook(CreateHookRequest $r)
    {
        $d = $r->validated();
        $res = $this->webhooks->create($d['url'], $d['events'], $d['description'] ?? null, $r->user()->id);

        return response()->json(['data' => $this->hookView($res['endpoint']) + ['secret' => $res['secret']], 'notice' => 'Copy the signing secret now: it is not shown again.'], 201);
    }

    public function updateHook(UpdateHookRequest $r, int $id)
    {
        $d = $r->validated();

        return response()->json(['data' => $this->hookView($this->webhooks->update($id, $d, $r->user()->id))]);
    }

    public function deleteHook(int $id)
    {
        $this->webhooks->delete($id);

        return response()->json(['data' => ['deleted' => $id]]);
    }

    public function rotateHook(int $id)
    {
        return response()->json(['data' => ['id' => $id, 'secret' => $this->webhooks->rotateSecret($id)], 'notice' => 'Copy the new signing secret now: the old one no longer works and this is not shown again.']);
    }

    public function pingHook(int $id)
    {
        return response()->json(['data' => ['delivery_id' => $this->webhooks->ping($id)]], 202);
    }

    public function deliveries(Request $r, int $id)
    {
        $this->webhooks->find($id);
        $rows = DB::table('webhook_deliveries')->where('endpoint_id', $id)->orderByDesc('id')->limit(100)
            ->get(['id', 'event_id', 'event', 'status', 'attempts', 'next_attempt_at', 'last_status_code', 'last_error', 'delivered_at', 'created_at']);

        return response()->json(['data' => $rows]);
    }

    public function replay(int $deliveryId)
    {
        return response()->json(['data' => $this->webhooks->replay($deliveryId)]);
    }
}
