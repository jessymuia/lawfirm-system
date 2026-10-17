<?php

namespace Tests\Feature;

use App\Entitlements\Entitlements;
use App\Licensing\LicenseManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** PLAT-2: signed, expiring, offline-verifiable license; expiry degrades to read-only, never destroys. */
#[\PHPUnit\Framework\Attributes\Group('single-mode')]   // self-hosted installs only (TEN-17)
class LicenseTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private string $dir;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/lic-'.uniqid();
        mkdir($this->dir);
        Artisan::call('license:keygen', ['--out' => $this->dir]);
        $this->publicKey = trim(file_get_contents("{$this->dir}/license-public.key"));

        config([
            'entitlements.license.required' => true,
            'entitlements.license.public_keys' => [$this->publicKey],
            'entitlements.license.path' => "{$this->dir}/installed.lic",
            'entitlements.license.clock_file' => "{$this->dir}/clock",
        ]);
        $this->reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        \Carbon\CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** Forget every memo so a new license/clock is seen, as it would be on the next request. */
    private function reset(): void
    {
        app(LicenseManager::class)->forget();
        app(Entitlements::class)->forget();
    }

    private function now(string $when): void
    {
        Carbon::setTestNow($when);
        \Carbon\CarbonImmutable::setTestNow($when);
        $this->reset();
    }

    /** Issue through the real vendor command; returns the file contents. */
    private function issue(array $opts = [], ?string $keyDir = null): string
    {
        $out = $this->dir.'/out-'.uniqid().'.lic';
        $code = Artisan::call('license:issue', array_merge([
            '--key' => ($keyDir ?? $this->dir).'/license-private.key', '--licensee' => 'Acme Advocates',
            '--plan' => 'professional', '--days' => 365, '--grace' => 30, '--out' => $out,
        ], $opts));
        $this->assertSame(0, $code, Artisan::output());

        return file_get_contents($out);
    }

    private function install(string $contents): \App\Licensing\LicenseStatus
    {
        $s = app(LicenseManager::class)->install($contents);
        $this->reset();

        return $s;
    }

    private function state(): string
    {
        return app(LicenseManager::class)->status()->state;
    }

    // ---- verification --------------------------------------------------------

    public function test_a_genuine_license_is_valid_and_grants_its_plan(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue());

        $this->assertSame('valid', $this->state());
        $e = app(Entitlements::class);
        $this->assertTrue($e->has('trust_accounting'));
        $this->assertSame(25, $e->limit('users'));
        $this->assertFalse($e->set()->readOnly);
        $this->assertNull($e->set()->notice);
    }

    public function test_license_feature_and_limit_overrides_apply(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue(['--feature' => ['sso=1', 'trust_accounting=0'], '--users' => 7]));

        $e = app(Entitlements::class);
        $this->assertTrue($e->has('sso'));
        $this->assertFalse($e->has('trust_accounting'));
        $this->assertSame(7, $e->limit('users'));
    }

    public function test_tampering_with_the_payload_breaks_the_signature(): void
    {
        $this->now('2026-10-01 10:00:00');
        $file = json_decode($this->issue(['--plan' => 'starter']), true);
        $file['payload'] = str_replace('"starter"', '"enterprise"', $file['payload']);

        $status = app(LicenseManager::class)->install(json_encode($file));

        $this->assertSame('invalid', $status->state);
        $this->assertFileDoesNotExist("{$this->dir}/installed.lic");
    }

    public function test_extending_the_expiry_by_hand_is_detected(): void
    {
        $this->now('2026-10-01 10:00:00');
        $file = json_decode($this->issue(['--days' => 5]), true);
        $payload = json_decode($file['payload'], true);
        $payload['expires_at'] = '2099-01-01T00:00:00+00:00';
        $file['payload'] = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->assertSame('invalid', app(LicenseManager::class)->install(json_encode($file))->state);
    }

    public function test_a_license_signed_by_an_unknown_key_is_rejected(): void
    {
        $other = $this->dir.'/other';
        Artisan::call('license:keygen', ['--out' => $other]);

        $this->assertSame('invalid', app(LicenseManager::class)->install($this->issue([], $other))->state);
    }

    public function test_garbage_and_truncated_files_are_rejected_not_crashed_on(): void
    {
        $good = json_decode($this->issue(), true);
        foreach (['', 'not json', '{}', '[]', json_encode(['payload' => 1, 'signature' => 2]),
            json_encode(['payload' => $good['payload'], 'signature' => 'AAAA']),
            json_encode(['payload' => $good['payload'], 'signature' => '!!!not-base64!!!'])] as $bad) {
            $this->assertSame('invalid', app(LicenseManager::class)->install($bad)->state, "input: {$bad}");
        }
    }

    public function test_key_rotation_accepts_a_license_from_any_trusted_key(): void
    {
        $new = $this->dir.'/new';
        Artisan::call('license:keygen', ['--out' => $new]);
        config(['entitlements.license.public_keys' => [$this->publicKey, trim(file_get_contents("{$new}/license-public.key"))]]);
        // the verifier captures its keys when first built; rebuild it as a fresh process would
        $this->app->forgetInstance(\App\Licensing\LicenseVerifier::class);
        $this->app->forgetInstance(LicenseManager::class);
        $this->now('2026-10-01 10:00:00');

        $this->assertSame('valid', $this->install($this->issue([], $new))->state);
        $this->assertSame('valid', $this->install($this->issue())->state);
    }

    public function test_a_license_issued_in_the_future_is_not_yet_valid(): void
    {
        $this->now('2026-10-01 10:00:00');
        $lic = $this->issue();
        $this->now('2026-09-01 10:00:00');   // a month before it was issued

        $this->assertSame('not_yet_valid', $this->install($lic)->state);
        $this->assertFalse(app(LicenseManager::class)->status()->allowsWrites());
    }

    // ---- term: valid -> grace -> expired -------------------------------------

    public function test_grace_period_keeps_everything_working_but_warns(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue(['--days' => 10, '--grace' => 30]));

        $this->now('2026-10-21 10:00:00');   // 10 days past expiry

        $this->assertSame('grace', $this->state());
        $e = app(Entitlements::class);
        $this->assertFalse($e->set()->readOnly);
        $this->assertStringContainsString('expired on', $e->set()->notice);
        $this->assertTrue($e->has('trust_accounting'));
    }

    public function test_after_grace_the_install_is_read_only_with_data_features_still_readable(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue(['--days' => 10, '--grace' => 30, '--feature' => ['sso=1']]));

        $this->now('2027-01-01 10:00:00');

        $this->assertSame('expired', $this->state());
        $set = app(Entitlements::class)->set();
        $this->assertTrue($set->readOnly);
        $this->assertTrue($set->has('trust_accounting'), 'their data stays reachable');
        $this->assertFalse($set->has('sso'), 'access paths switch off');
        $this->assertSame('professional', $set->plan);
    }

    public function test_winding_the_clock_back_does_not_revive_an_expired_license(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue(['--days' => 10, '--grace' => 0]));

        $this->now('2027-03-01 10:00:00');
        $this->assertSame('expired', $this->state());     // records the latest time seen

        $this->now('2026-10-05 10:00:00');                // admin sets the clock back into the term
        $this->assertSame('expired', $this->state());
    }

    // ---- behaviour over HTTP -------------------------------------------------

    public function test_expired_install_refuses_writes_but_serves_reads_and_exports(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue(['--days' => 10, '--grace' => 0]));
        $user = $this->userWithPermissions(['create-clients', 'list-clients', 'delete-clients', 'export-firm-data', 'list-cases']);
        $client = \App\Models\Client::factory()->create();
        $this->now('2027-01-01 10:00:00');

        $this->actingAs($user)->postJson('/api/v1/clients', [])->assertStatus(403)->assertJson(['code' => 'license_expired']);
        $this->actingAs($user)->deleteJson("/api/v1/clients/{$client->id}")->assertStatus(403)->assertJson(['code' => 'license_expired']);
        $this->assertNotNull(\App\Models\Client::find($client->id), 'nothing was deleted');

        $this->actingAs($user)->getJson('/api/v1/clients')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/export/firm-data')->assertOk();
        $this->assertNotEmpty($this->actingAs($user)->getJson('/api/v1/clients')->headers->get('X-License-Notice'));
    }

    public function test_sign_in_and_license_install_stay_open_while_read_only(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue(['--days' => 10, '--grace' => 0]));
        $this->now('2027-01-01 10:00:00');

        $this->postJson('/api/v1/login', ['email' => 'x@y.z', 'password' => 'nope'])
            ->assertStatus(401)->assertJsonMissing(['code' => 'license_expired']);
        $admin = $this->userWithPermissions(['manage-license']);
        $renewed = $this->issue();   // issued "now" (2027-01-01)

        $this->actingAs($admin)->postJson('/api/v1/license', ['license' => $renewed])->assertOk()->assertJson(['state' => 'valid']);
        $this->reset();
        $this->actingAs($this->userWithPermissions(['create-clients']))->postJson('/api/v1/clients', [])->assertStatus(422);   // reached validation: writes are back
    }

    public function test_missing_license_is_read_only_when_required_and_normal_when_not(): void
    {
        $user = $this->userWithPermissions(['create-clients']);
        $this->reset();

        $this->actingAs($user)->postJson('/api/v1/clients', [])->assertStatus(403)->assertJson(['code' => 'license_expired']);

        config(['entitlements.license.required' => false]);
        $this->reset();
        $this->actingAs($user)->postJson('/api/v1/clients', [])->assertStatus(422);
    }

    public function test_installing_a_bad_file_does_not_replace_a_working_license(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue());
        $before = file_get_contents("{$this->dir}/installed.lic");

        $this->assertSame('invalid', app(LicenseManager::class)->install('{"payload":"x","signature":"y"}')->state);

        $this->assertSame($before, file_get_contents("{$this->dir}/installed.lic"));
        $this->reset();
        $this->assertSame('valid', $this->state());
    }

    public function test_installing_needs_the_manage_license_permission_and_validates_input(): void
    {
        $this->now('2026-10-01 10:00:00');
        $lic = $this->issue();

        $this->actingAs($this->userWithPermissions(['list-clients']))->postJson('/api/v1/license', ['license' => $lic])->assertStatus(403);
        $this->actingAs($this->userWithPermissions(['manage-license']))->postJson('/api/v1/license', [])->assertStatus(422);
        $this->actingAs($this->userWithPermissions(['manage-license']))->postJson('/api/v1/license', ['license' => 'junk'])->assertStatus(422);
    }

    public function test_license_status_endpoint_needs_login_and_reports_state(): void
    {
        $this->now('2026-10-01 10:00:00');
        $this->install($this->issue());

        $this->getJson('/api/v1/license')->assertStatus(401);
        $this->actingAs($this->userWithPermissions([]))->getJson('/api/v1/license')
            ->assertOk()->assertJson(['state' => 'valid', 'licensee' => 'Acme Advocates', 'read_only' => false]);
    }

    public function test_the_license_is_ignored_in_saas_mode(): void
    {
        // SaaS entitlements come from the tenant plan (PLAT-1); a missing license must never make SaaS read-only.
        config(['tenancy.mode' => 'multi']);
        $this->app->forgetInstance(\App\Entitlements\EntitlementSource::class);
        $this->app->forgetInstance(Entitlements::class);

        $this->assertInstanceOf(\App\Entitlements\TenantPlanSource::class, app(\App\Entitlements\EntitlementSource::class));
        $this->assertFalse(app(Entitlements::class)->set()->readOnly);
    }

    public function test_cli_status_reflects_the_state(): void
    {
        $this->artisan('license:status')->assertExitCode(1);   // missing

        $this->now('2026-10-01 10:00:00');
        $file = "{$this->dir}/cli.lic";
        file_put_contents($file, $this->issue());
        $this->artisan('license:install', ['file' => $file])->assertExitCode(0);
        $this->reset();
        $this->artisan('license:status')->assertExitCode(0);
    }

    public function test_the_private_key_is_never_part_of_the_application(): void
    {
        $this->assertArrayNotHasKey('private_key', config('entitlements.license'));
        $hits = [];
        foreach (['app', 'config', 'routes', 'database'] as $d) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($d))) as $f) {
                if ($f->isFile() && preg_match('/license-private\.key$/', $f->getFilename())) {
                    $hits[] = $f->getPathname();
                }
            }
        }
        $this->assertSame([], $hits);
    }
}
