<?php

namespace App\Http\Controllers;

use App\Jobs\RunTenantBackup;
use App\Platform\PlatformAudit;
use App\Platform\PlatformAuth;
use App\Platform\PlatformStaff;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantMigrator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * PLAT-7: what platform staff can do to the fleet. Every mutating action is audited (ok, denied or failed), and nothing here
 * reads tenant business data: usage is counts and sizes only.
 */
class PlatformConsoleController extends Controller
{
    private static ?string $latest = null;

    public function __construct(private TenantLifecycle $lifecycle, private TenantMigrator $migrator) {}

    private function staff(Request $r): PlatformStaff
    {
        return $r->attributes->get('platform_staff');
    }

    // ---- session -----------------------------------------------------------------------------------------------

    public function login(\App\Http\Requests\PlatformConsole\LoginRequest $r, PlatformAuth $auth): JsonResponse
    {
        $d = $r->validated();
        $res = $auth->login($d['email'], $d['password'], $r->ip());
        if (! $res) {
            return response()->json(['message' => 'These credentials do not match.'], 401);
        }
        if (! empty($res['mfa_required'])) {
            return response()->json(['mfa_required' => true, 'challenge' => $res['challenge']]);
        }

        return $this->tokenResponse($res);
    }

    /** SEC-12: second login step. */
    public function loginMfa(\App\Http\Requests\PlatformConsole\LoginMfaRequest $r, PlatformAuth $auth): JsonResponse
    {
        $d = $r->validated();
        $res = $auth->completeMfa($d['challenge'], $d['code'], $r->ip());

        return $res ? $this->tokenResponse($res) : response()->json(['message' => 'That code is not right, or the sign-in expired. Start again.'], 401);
    }

    private function tokenResponse(array $res): JsonResponse
    {
        return response()->json(['token' => $res['token'], 'expires_at' => $res['expires_at'], 'staff' => ['name' => $res['staff']->name, 'role' => $res['staff']->role]]
            + (! empty($res['enroll_only']) ? ['mfa_enrollment_required' => true, 'message' => 'Set up two-factor authentication: this token can do nothing else yet.'] : []));
    }

    // ---- SEC-12: staff second factor ---------------------------------------------------------------------------

    public function mfaStatus(Request $r, PlatformAuth $auth): JsonResponse
    {
        $s = $this->staff($r);

        return response()->json(['enabled' => $auth->mfa()->enabled($s->id), 'required' => config('tenancy.platform_mfa') === 'required', 'recovery_codes_left' => $auth->mfa()->remaining($s->id)]);
    }

    public function mfaSetup(Request $r, PlatformAuth $auth): JsonResponse
    {
        $s = $this->staff($r);
        try {
            return response()->json($auth->mfa()->begin($s->id, $s->email, 'Platform console'));
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function mfaEnable(\App\Http\Requests\PlatformConsole\MfaEnableRequest $r, PlatformAuth $auth): JsonResponse
    {
        $d = $r->validated();
        $s = $this->staff($r);
        try {
            $codes = $auth->mfa()->confirm($s->id, $d['code']);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        $auth->liftEnrollOnly($r->bearerToken());
        PlatformAudit::record($s, 'mfa.enabled', null, [], 'ok', null, $r->ip());

        return response()->json(['recovery_codes' => $codes, 'message' => 'Two-factor authentication is on. Recovery codes are shown once.']);
    }

    /** Password and a current code; refused outright when the platform requires two-factor. */
    public function mfaDisable(\App\Http\Requests\PlatformConsole\MfaDisableRequest $r, PlatformAuth $auth): JsonResponse
    {
        $d = $r->validated();
        $s = $this->staff($r);
        if (config('tenancy.platform_mfa') === 'required') {
            return response()->json(['message' => 'Two-factor authentication is required for platform staff.'], 403);
        }
        if (! \Illuminate\Support\Facades\Hash::check($d['password'], $s->password) || ! $auth->mfa()->verify($s->id, $d['code'])) {
            return response()->json(['message' => 'Password or code is not right.'], 422);
        }
        $auth->mfa()->disable($s->id);
        PlatformAudit::record($s, 'mfa.disabled', null, [], 'ok', null, $r->ip());

        return response()->json(['message' => 'Two-factor authentication is off.']);
    }

    public function logout(Request $r, PlatformAuth $auth): JsonResponse
    {
        $auth->logout($r->bearerToken());

        return response()->json(['message' => 'ok']);
    }

    public function me(Request $r): JsonResponse
    {
        $s = $this->staff($r);

        return response()->json(['name' => $s->name, 'email' => $s->email, 'role' => $s->role, 'abilities' => PlatformStaff::ABILITIES[$s->role]]);
    }

    /** The signed-in operator edits their own display name. Email and role stay CLI-managed. */
    public function updateMe(\App\Http\Requests\Platform\UpdateProfileRequest $r): JsonResponse
    {
        $d = $r->validated();
        $s = $this->staff($r);
        $s->update(['name' => trim($d['name'])]);
        PlatformAudit::record($s, 'account.name_changed', null, [], 'ok', null, $r->ip());

        return response()->json(['name' => $s->name, 'email' => $s->email, 'role' => $s->role]);
    }

    /** Change the signed-in operator's password. Needs the current password; every other session is signed out. */
    public function changePassword(\App\Http\Requests\Platform\ChangePasswordRequest $r): JsonResponse
    {
        $d = $r->validated();
        $s = $this->staff($r);
        if (! \Illuminate\Support\Facades\Hash::check($d['current_password'], $s->password)) {
            PlatformAudit::record($s, 'account.password_changed', null, [], 'denied', 'wrong current password', $r->ip());

            return response()->json(['message' => 'Your current password is not right.', 'errors' => ['current_password' => ['Your current password is not right.']]], 422);
        }
        $s->update(['password' => \Illuminate\Support\Facades\Hash::make($d['password'])]);
        DB::connection(config('tenancy.control_connection'))->table('platform_tokens')
            ->where('staff_id', $s->id)->where('token_hash', '!=', hash('sha256', (string) $r->bearerToken()))->delete();
        PlatformAudit::record($s, 'account.password_changed', null, [], 'ok', null, $r->ip());

        return response()->json(['message' => 'Password changed. Other sessions were signed out.']);
    }

    // ---- read --------------------------------------------------------------------------------------------------

    public function tenants(Request $r): JsonResponse
    {
        $q = Tenant::with('domains')->orderBy('slug');
        if ($r->filled('status')) {
            $q->where('status', $r->input('status'));
        }
        if ($r->filled('q')) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $r->input('q')).'%';
            $q->where(fn ($w) => $w->where('slug', 'like', $like)->orWhere('name', 'like', $like));
        }
        $latest = $this->latestUsage();
        $lastActive = $this->lastActiveDays();
        $backups = $this->lastBackups();

        return response()->json(['data' => $q->get()->map(fn ($t) => $this->summary($t, $latest[$t->id] ?? []) + [
            // last day the firm made any API call, and the outcome of its newest backup (from the audit log)
            'last_active_day' => $lastActive[$t->id] ?? null,
            'last_backup' => $backups[$t->slug] ?? null,
        ])->values()]);
    }

    public function tenant(string $slug): JsonResponse
    {
        $t = Tenant::with('domains')->where('slug', $slug)->firstOrFail();
        $history = DB::connection(config('tenancy.control_connection'))->table('tenant_usage_daily')
            ->where('tenant_id', $t->id)->where('day', '>=', now()->subDays(29)->toDateString())->orderBy('day')->get(['day', 'metric', 'value'])
            ->groupBy('metric')->map(fn ($rows) => $rows->map(fn ($r) => ['day' => $r->day, 'value' => (int) $r->value])->values());

        $audit = PlatformAudit::where('tenant_slug', $slug)->orderByDesc('id')->limit(25)->get(['id', 'staff_email', 'action', 'result', 'detail', 'created_at']);

        return response()->json(['data' => $this->summary($t, $this->latestUsage()[$t->id] ?? []) + [
            'entitlements' => $t->entitlements,
            'migration_error' => $t->migration_error,
            'migration_attempted_at' => $t->migration_attempted_at,
            'usage_history' => $history,
            'recent_actions' => $audit,
        ]]);
    }

    /** TEN-4: every cell with its load against capacity; a cell with firms but no row is the template-only default. */
    public function cells(\App\Tenancy\CellRegistry $registry): JsonResponse
    {
        $load = $registry->load();
        $rows = [];
        foreach (\App\Tenancy\Models\Cell::orderBy('name')->get() as $c) {
            $rows[] = ['name' => $c->name, 'region' => $c->region, 'host' => $c->host, 'capacity' => $c->capacity, 'accepting' => $c->accepting,
                'firms' => $load[$c->name] ?? 0, 'utilisation' => round(($load[$c->name] ?? 0) / max(1, $c->capacity), 3),
                'files_disk' => $c->files_disk, 'queue' => $c->queue];
        }
        foreach (array_diff_key($load, array_flip(array_column($rows, 'name'))) as $name => $n) {
            $rows[] = ['name' => $name, 'region' => null, 'host' => null, 'capacity' => null, 'accepting' => null, 'firms' => $n, 'utilisation' => null, 'files_disk' => null, 'queue' => null];
        }

        return response()->json(['data' => $rows]);
    }

    public function dashboard(): \Illuminate\Http\Response
    {
        $nonce = base64_encode(random_bytes(16));

        return response(view('platform.fleet', ['nonce' => $nonce])->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'",
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** PLAT-7: independent browser console, served only at /console on PLATFORM_ADMIN_HOST. */
    public function console(): \Illuminate\Http\Response
    {
        return response(view('platform.console')->render(), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; font-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function fleet(): JsonResponse
    {
        $tenants = Tenant::all();
        $live = $tenants->whereNotIn('status', ['offboarded', 'purged']);
        $latest = TenantMigrator::latestVersion();

        return response()->json(['data' => [
            'tenants' => $tenants->count(),
            // TEN-7: where the fleet stands against this release
            'latest_version' => $latest,
            'behind' => $live->filter(fn ($t) => $t->schema_version === null || strcmp((string) $t->schema_version, (string) $latest) < 0)->count(),
            'by_migration_status' => $live->groupBy('migration_status')->map->count(),
            'by_status' => $tenants->groupBy('status')->map->count(),
            'schema_versions' => $tenants->whereNotIn('status', ['purged'])->groupBy(fn ($t) => $t->schema_version ?? '(none)')->map->count(),
            'failed_migrations' => $tenants->where('migration_status', 'failed')->map(fn ($t) => ['slug' => $t->slug, 'error' => $t->migration_error])->values(),
            'legal_holds' => $tenants->where('legal_hold', true)->pluck('slug')->values(),
            // TEN-8: firms whose last `schema:drift` found differences
            'schema_drift' => $tenants->filter(fn ($t) => ! empty($t->schema_drift))->map(fn ($t) => ['slug' => $t->slug, 'differences' => $t->schema_drift, 'checked_at' => $t->drift_checked_at?->toIso8601String()])->values(),
        ]]);
    }

    public function audit(Request $r): JsonResponse
    {
        $q = PlatformAudit::orderByDesc('id');
        foreach (['tenant_slug', 'staff_email', 'action', 'result'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, $r->input($f));
            }
        }

        return response()->json(['data' => $q->limit(min(500, (int) $r->input('limit', 100)))->get()]);
    }

    /** Which of the firm's own channels (M-Pesa, card, SMS, email) are set up, field by field, and whose accounts they are. No values. */
    public function integrations(string $slug): JsonResponse
    {
        $t = Tenant::with('domains')->where('slug', $slug)->firstOrFail();
        $data = app(\App\Tenancy\TenantManager::class)->runIn($t, function () {
            $c = app(\App\Secrets\IntegrationCredentials::class);
            $out = [];
            foreach (array_keys(\App\Secrets\IntegrationCredentials::PROVIDERS) as $p) {
                $out[$p] = ['fields' => $c->status($p)] + $c->readiness($p);
            }

            return $out;
        }, allowInactive: true);

        return response()->json(['data' => $data, 'meta' => ['own_only' => (bool) config('tenancy.own_credentials'), 'host' => $t->domains->first()?->domain]]);
    }

    /**
     * The operator enters (or removes) a firm's own credentials after creating it. Write-only, like the firm's own page:
     * values are encrypted into the firm's database and never returned; the audit trail records field names only.
     */
    public function setIntegration(\App\Http\Requests\Platform\IntegrationRequest $r, string $slug, string $provider): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        abort_unless(isset(\App\Secrets\IntegrationCredentials::PROVIDERS[$provider]), 404);
        $d = $r->validated();
        if ($d === []) {
            return response()->json(['message' => 'Nothing to save.'], 422);
        }

        return $this->act($r, 'tenant.integration', $slug, ['provider' => $provider, 'fields' => array_keys($d)], function () use ($t, $provider, $d) {
            $out = app(\App\Tenancy\TenantManager::class)->runIn($t, function () use ($provider, $d) {
                $c = app(\App\Secrets\IntegrationCredentials::class);
                $c->set($provider, $d, null);

                return ['fields' => $c->status($provider)] + $c->readiness($provider);
            }, allowInactive: true);

            return ['status' => 200, 'body' => ['data' => $out]];
        });
    }

    // ---- operate -----------------------------------------------------------------------------------------------

    public function provision(\App\Http\Requests\PlatformConsole\ProvisionRequest $r): JsonResponse
    {
        $d = $r->validated();

        return $this->act($r, 'tenant.provision', $d['slug'], \Illuminate\Support\Arr::only($d, ['slug', 'name', 'admin_email', 'plan', 'currency', 'trial_days', 'domain', 'region', 'cell']), function () use ($d, $r) {
            $res = $this->lifecycle->provision($d);
            $tenant = $res['tenant'];
            $notes = [];

            // allocate the chosen plan: a real subscription (trial, or paid with its first invoice), not just a label
            $plan = $d['plan'] ?? null;
            $subscription = null;
            try {
                $billing = app(\App\Billing\Billing::class);
                if (! $billing->subscription($tenant)) {
                    $currency = $d['currency'] ?? (string) config('billing.trial.currency', 'KES');
                    $subscription = (! $plan || $plan === 'trial')
                        ? $billing->startTrial($tenant, $d['admin_email'], $currency)
                        : $billing->subscribe($tenant, $plan, $currency, 'manual', $d['admin_email'], (int) ($d['trial_days'] ?? 0));
                }
            } catch (\Throwable $e) {
                $notes[] = 'The firm was created but the plan could not be allocated: '.$e->getMessage().' Allocate it from the firm\'s billing.';
            }

            // the new firm starts under its own name and colour (it can change both in its Branding settings)
            try {
                app(\App\Tenancy\TenantManager::class)->runIn($tenant, fn () => \App\Branding\Branding::update(array_filter(['name' => $tenant->name, 'color' => $d['brand_color'] ?? null]), null));
            } catch (\Throwable $e) {
                $notes[] = 'The firm\'s branding could not be pre-filled: '.$e->getMessage();
            }

            $mail = $this->sendWelcome($tenant, $d, $res['admin_password'], $subscription);
            if ($mail !== 'sent') {
                $notes[] = $mail;
            }

            return ['status' => 201, 'body' => [
                'data' => $this->summary($tenant->fresh()->load('domains'), []),
                'admin_password' => $res['admin_password'],
                'welcome_email' => $mail === 'sent' ? 'sent' : 'failed',
                'note' => implode(' ', $notes) ?: null,
            ]];
        });
    }

    /** Emails the first administrator their sign-in details. Never fails the provisioning: returns 'sent' or the reason. */
    private function sendWelcome(Tenant $tenant, array $d, ?string $password, ?object $subscription): string
    {
        try {
            $domain = $tenant->domains()->value('domain');
            $app = parse_url((string) config('app.url'));
            $host = (string) ($app['host'] ?? 'localhost');
            $port = (isset($app['port']) && ($host === 'localhost' || str_ends_with((string) $domain, '.localhost'))) ? ':'.$app['port'] : '';
            $scheme = $app['scheme'] ?? 'https';
            $loginUrl = $domain ? "{$scheme}://{$domain}{$port}/login" : rtrim((string) config('app.url'), '/').'/login';
            $planCode = $tenant->plan ?: 'trial';
            $planName = (string) (collect(\App\Billing\PlanStore::all())->firstWhere('code', $planCode)['name'] ?? ucfirst($planCode));
            $trialEnds = $subscription && $subscription->trial_ends_at ? \Carbon\Carbon::parse($subscription->trial_ends_at)->format('d/m/Y') : null;

            \Illuminate\Support\Facades\Mail::to($d['admin_email'])->send(new \App\Mail\FirmWelcome(
                (string) config('app.name'), $tenant->name, (string) ($d['admin_name'] ?? ''), $d['admin_email'], $loginUrl, $password, $planName, $trialEnds,
            ));

            return 'sent';
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('firm welcome email failed', ['tenant' => $tenant->slug, 'error' => $e->getMessage()]);

            return 'The welcome email could not be sent ('.\Illuminate\Support\Str::limit($e->getMessage(), 120).'). Give the administrator their sign-in details yourself.';
        }
    }

    public function suspend(\App\Http\Requests\PlatformConsole\ReasonRequest $r, string $slug): JsonResponse
    {
        return $this->lifecycleAction($r, $slug, 'tenant.suspend', fn ($t) => $this->lifecycle->suspend($t));
    }

    public function resume(\App\Http\Requests\PlatformConsole\ReasonRequest $r, string $slug): JsonResponse
    {
        return $this->lifecycleAction($r, $slug, 'tenant.resume', fn ($t) => $this->lifecycle->resume($t));
    }

    public function migrate(Request $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();

        return $this->act($r, 'tenant.migrate', $slug, [], function () use ($t) {
            $ok = $this->migrator->migrate($t);
            if ($this->migrator->lastResult === 'busy') {
                return ['status' => 409, 'body' => ['message' => 'A migration of this firm is already running.', 'code' => 'migration_running'], 'result' => 'busy', 'detail' => null];
            }
            $t = $t->fresh();

            return ['status' => $ok ? 200 : 500, 'body' => ['data' => ['ok' => $ok, 'schema_version' => $t->schema_version, 'migration_status' => $t->migration_status, 'error' => $t->migration_error]], 'result' => $ok ? 'ok' : 'failed', 'detail' => $t->migration_error];
        });
    }

    public function backup(Request $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        if (in_array($t->status, ['purged'], true)) {
            return response()->json(['message' => 'Tenant has been purged.'], 422);
        }
        $staff = $this->staff($r);
        PlatformAudit::record($staff, 'tenant.backup.requested', $slug, [], 'ok', null, $r->ip());
        RunTenantBackup::dispatch($slug, $staff->id, $staff->email);

        return response()->json(['message' => 'Backup queued. The outcome is recorded in the audit log (tenant.backup.finished).'], 202);
    }

    // ---- destructive (admin role + typed confirmation) -------------------------------------------------------------

    /** PLAT-9: eject a firm's data, documents and audit trail as a bundle. Data leaving the platform: admin only, reason required. */
    public function eject(\App\Http\Requests\PlatformConsole\EjectRequest $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $d = $r->validated();
        if ($t->status === 'purged') {
            return response()->json(['message' => 'Tenant has been purged; there is nothing to eject.'], 422);
        }
        $staff = $this->staff($r);
        PlatformAudit::record($staff, 'tenant.eject.requested', $slug, ['reason' => $d['reason']], 'ok', null, $r->ip());
        \App\Jobs\RunTenantEject::dispatch($slug, $staff->id, $staff->email);

        return response()->json(['message' => 'Eject queued. The bundle path and sha256 are recorded in the audit log (tenant.eject.finished).'], 202);
    }

    public function offboard(\App\Http\Requests\PlatformConsole\OffboardRequest $r, string $slug): JsonResponse
    {
        $d = $r->validated();

        return $this->lifecycleAction($r, $slug, 'tenant.offboard', fn ($t) => $this->lifecycle->offboard($t, $d['retention_days'] ?? null), confirm: true);
    }

    public function purge(\App\Http\Requests\PlatformConsole\PurgeRequest $r, string $slug): JsonResponse
    {
        $d = $r->validated();

        return $this->lifecycleAction($r, $slug, 'tenant.purge', fn ($t) => $this->lifecycle->purge($t, $d['without_export'] ?? null), confirm: true);
    }

    /** TEN-9: hand over an offboarded firm's final export (the full PLAT-9 bundle). Admin only, audited. */
    public function finalExport(Request $r, string $slug): \Symfony\Component\HttpFoundation\Response
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $path = $t->final_export_path;
        if (! $path || ! is_file($path)) {
            PlatformAudit::record($this->staff($r), 'tenant.final_export.download', $slug, [], 'failed', 'no final export', $r->ip());

            return response()->json(['message' => 'This firm has no final export.', 'error' => $t->final_export_error], 404);
        }
        PlatformAudit::record($this->staff($r), 'tenant.final_export.download', $slug, ['sha256' => $t->final_export_sha256], 'ok', null, $r->ip());

        return response()->download($path, basename($path), ['X-Content-SHA256' => (string) $t->final_export_sha256]);
    }

    public function legalHold(\App\Http\Requests\PlatformConsole\LegalHoldRequest $r, string $slug): JsonResponse
    {
        $d = $r->validated();

        return $this->lifecycleAction($r, $slug, $d['hold'] ? 'tenant.legal_hold.set' : 'tenant.legal_hold.clear', fn ($t) => $this->lifecycle->setLegalHold($t, (bool) $d['hold']));
    }

    // ---- PLAT-3: feature flags per tenant ----------------------------------------------------------------------

    public function flags(string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $this->flagView($t)]);
    }

    /** Body {"flags": {"name": true|false|null}}: true/false forces it for this firm, null removes the override. */
    public function setFlags(\App\Http\Requests\PlatformConsole\SetFlagsRequest $r, string $slug): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        $d = $r->validated();
        $known = array_keys(\App\Flags\Flags::registry());
        $unknown = array_diff(array_keys($d['flags']), $known);

        return $this->act($r, 'tenant.flags', $slug, ['flags' => $d['flags'], 'reason' => $d['reason']], function () use ($t, $d, $unknown) {
            if ($unknown) {
                throw new \InvalidArgumentException('Unknown flag(s): '.implode(', ', $unknown));
            }
            $flags = (array) $t->flags;
            foreach ($d['flags'] as $name => $value) {
                if ($value === null) {
                    unset($flags[$name]);
                } else {
                    $flags[$name] = (bool) $value;
                }
            }
            $t->update(['flags' => $flags ?: null]);

            return ['status' => 200, 'body' => ['data' => $this->flagView($t->fresh())]];
        });
    }

    private function flagView(Tenant $t): array
    {
        $overrides = (array) $t->flags;
        $out = [];
        foreach (\App\Flags\Flags::registry() as $name => $def) {
            $out[] = ['flag' => $name, 'type' => $def['type'] ?? null, 'description' => $def['description'] ?? '', 'default' => (bool) ($def['default'] ?? false),
                'tenant_override' => $overrides[$name] ?? null];
        }

        return $out;
    }

    // ---- internals ---------------------------------------------------------------------------------------------

    private function lifecycleAction(Request $r, string $slug, string $action, \Closure $fn, bool $confirm = false): JsonResponse
    {
        $t = Tenant::where('slug', $slug)->firstOrFail();
        // the reason (and `confirm`) were validated by each action's Form Request (ENG-8)
        if ($confirm && $r->input('confirm') !== $slug) {
            PlatformAudit::record($this->staff($r), $action, $slug, ['reason' => $r->input('reason')], 'denied', 'confirmation did not match the tenant slug', $r->ip());

            return response()->json(['message' => 'Type the tenant slug in `confirm` to proceed.'], 422);
        }

        return $this->act($r, $action, $slug, ['reason' => $r->input('reason')], function () use ($fn, $t) {
            $fn($t);

            return ['status' => 200, 'body' => ['data' => $this->summary($t->fresh()->load('domains'), [])]];
        });
    }

    /** Run an action, audit it either way, and turn domain refusals (RuntimeException) into a 422 rather than a 500. */
    private function act(Request $r, string $action, ?string $slug, array $params, \Closure $fn): JsonResponse
    {
        $staff = $this->staff($r);
        try {
            $out = $fn();
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            PlatformAudit::record($staff, $action, $slug, $params, 'failed', $e->getMessage(), $r->ip());

            return response()->json(['message' => $e->getMessage()], 422);
        }
        PlatformAudit::record($staff, $action, $slug, $params, $out['result'] ?? 'ok', $out['detail'] ?? null, $r->ip());

        return response()->json($out['body'], $out['status']);
    }

    /** @return array<int, array<string,int>> tenant id => metric => latest value */
    /** @return array<int,string> tenant id => most recent day with any API call */
    private function lastActiveDays(): array
    {
        return DB::connection(config('tenancy.control_connection'))->table('tenant_usage_daily')
            ->where('metric', \App\Observability\UsageMetrics::API_CALLS)->where('value', '>', 0)
            ->groupBy('tenant_id')->selectRaw('tenant_id, max(day) as d')->pluck('d', 'tenant_id')->all();
    }

    /**
     * @return array<string,array{at:string,result:string}> firm slug => its newest backup: the newest file on the backup disk
     *         (nightly and manual runs both leave one), or a failed manual run recorded in the audit log if that is newer.
     */
    private function lastBackups(): array
    {
        $out = [];
        try {
            $disk = \Illuminate\Support\Facades\Storage::disk(config('backup.disk'));
            foreach (Tenant::pluck('slug') as $slug) {
                $newest = 0;
                foreach ($disk->allFiles('tenants/'.$slug) as $file) {
                    $newest = max($newest, $disk->lastModified($file));
                }
                if ($newest > 0) {
                    $out[$slug] = ['at' => date('c', $newest), 'result' => 'ok'];
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
        foreach (PlatformAudit::where('action', 'tenant.backup.finished')->orderByDesc('id')->limit(500)->get(['tenant_slug', 'result', 'created_at']) as $row) {
            $seen = $seen ?? [];
            if (isset($seen[$row->tenant_slug])) {
                continue;
            }
            $seen[$row->tenant_slug] = true;
            if ($row->result !== 'ok' && (! isset($out[$row->tenant_slug]) || strtotime((string) $row->created_at) > strtotime($out[$row->tenant_slug]['at']))) {
                $out[$row->tenant_slug] = ['at' => (string) $row->created_at, 'result' => 'failed'];
            }
        }

        return $out;
    }

    private function latestUsage(): array
    {
        $rows = DB::connection(config('tenancy.control_connection'))->table('tenant_usage_daily as u')
            ->whereRaw('u.day = (select max(day) from tenant_usage_daily where tenant_id = u.tenant_id and metric = u.metric)')
            ->get(['tenant_id', 'metric', 'value', 'day']);
        $out = [];
        foreach ($rows as $row) {
            $out[$row->tenant_id][$row->metric] = (int) $row->value;
            $out[$row->tenant_id]['_day'] = $row->day;
        }

        return $out;
    }

    private function summary(Tenant $t, array $usage): array
    {
        return [
            'slug' => $t->slug, 'name' => $t->name, 'status' => $t->status, 'plan' => $t->plan, 'region' => $t->region, 'cell' => $t->cell,
            'domains' => $t->domains->pluck('domain')->values(),
            'schema_version' => $t->schema_version, 'migration_status' => $t->migration_status,
            // the console offers "Run system updates" where the firm is behind this release or its last update failed
            'update_needed' => ! in_array($t->status, ['offboarded', 'purged'], true)
                && ($t->migration_status === 'failed' || $t->schema_version === null || strcmp((string) $t->schema_version, (string) (self::$latest ??= TenantMigrator::latestVersion())) < 0),
            'files_disk' => $t->files_disk, 'queue' => $t->queue,   // TEN-2: where its files and background work live
            'legal_hold' => (bool) $t->legal_hold, 'offboarded_at' => $t->offboarded_at, 'purge_after' => $t->purge_after,
            // TEN-9: deletion state (the export path stays server-side)
            'final_export' => $t->final_export_at ? ['at' => $t->final_export_at, 'sha256' => $t->final_export_sha256] : null,
            'final_export_error' => $t->final_export_error, 'purge_report' => $t->purge_report,
            'created_at' => $t->created_at,
            'usage' => collect($usage)->except('_day')->all(), 'usage_day' => $usage['_day'] ?? null,
        ];
    }
}
