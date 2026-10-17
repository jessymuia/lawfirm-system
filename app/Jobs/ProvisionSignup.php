<?php

namespace App\Jobs;

use App\Billing\Billing;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PLAT-13: create the firm a verified signup asked for: database, schema, catalog, its first administrator and a free
 * trial. Provisioning is resumable (TEN-9), so a failed run is retried by opening the link again.
 */
class ProvisionSignup implements \App\Tenancy\PlatformJob, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $signupId) {}

    public function handle(TenantLifecycle $lifecycle, Billing $billing): void
    {
        $db = DB::connection('control');
        $s = $db->table('signups')->where('id', $this->signupId)->first();
        if (! $s || $s->status !== 'provisioning') {
            return;
        }

        try {
            $tenant = Tenant::where('slug', $s->slug)->first();
            // an existing, finished firm with this address is ours only if an earlier run recorded it
            if ($tenant && $tenant->status !== 'provisioning' && (int) $s->tenant_id !== (int) $tenant->id) {
                throw new \RuntimeException("slug [{$s->slug}] was taken by another firm before provisioning");
            }
            if (! $tenant || $tenant->status === 'provisioning') {
                $tenant = $lifecycle->provision([
                    'slug' => $s->slug, 'name' => $s->firm_name, 'admin_email' => $s->email, 'admin_name' => $s->name,
                    'admin_phone' => (string) $s->phone, 'admin_password_hash' => $s->password, 'region' => $s->region, 'plan' => 'trial',
                ])['tenant'];
                $db->table('signups')->where('id', $s->id)->update(['tenant_id' => $tenant->id, 'updated_at' => now()]);
            }
            if (! $billing->subscription($tenant)) {
                $billing->startTrial($tenant, $s->email, $s->currency);
            }
            $db->table('signups')->where('id', $s->id)->update([
                'status' => 'ready', 'tenant_id' => $tenant->id, 'password' => null, 'provisioned_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('signup.provision failed', ['signup' => $s->id, 'slug' => $s->slug, 'error' => $e->getMessage()]);
            $db->table('signups')->where('id', $s->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => now()]);
        }
    }
}
