<?php

namespace Tests\Feature;

use App\Entitlements\EntitlementException;
use App\Entitlements\Entitlements;
use App\Exceptions\Handler;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** PLAT-1 (single-tenant / self-hosted source): one API, fail-closed, enforced at the route. */
class EntitlementsTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_self_hosted_plan_has_everything_unlimited(): void
    {
        $e = app(Entitlements::class);

        foreach (config('entitlements.features') as $f) {
            $this->assertTrue($e->has($f), $f);
        }
        $this->assertNull($e->limit('users'));
        $this->assertTrue($e->within('users', 10_000));
    }

    public function test_unknown_plan_grants_nothing(): void
    {
        $this->usePlan('platinum-typo');
        $e = app(Entitlements::class);

        foreach (config('entitlements.features') as $f) {
            $this->assertFalse($e->has($f), "{$f} must be denied for an unknown plan");
        }
        $this->assertSame(0, $e->limit('users'));
        $this->assertFalse($e->within('users'));
    }

    public function test_unknown_feature_and_limit_are_denied(): void
    {
        $e = app(Entitlements::class);

        $this->assertFalse($e->has('made_up_feature'));
        $this->assertSame(0, $e->limit('made_up_limit'));
    }

    public function test_require_throws_a_typed_exception(): void
    {
        $this->usePlan('starter');

        $this->expectException(EntitlementException::class);
        app(Entitlements::class)->require('trust_accounting');
    }

    public function test_route_is_denied_when_the_plan_lacks_the_feature(): void
    {
        $this->usePlan('starter');
        $user = $this->userWithPermissions(['list-trust-transactions']);

        $this->actingAs($user)->getJson('/api/v1/trust-transactions')
            ->assertStatus(403)
            ->assertJson(['code' => 'entitlement_required', 'feature' => 'trust_accounting']);
    }

    public function test_route_is_open_when_the_plan_has_the_feature(): void
    {
        $this->usePlan('professional');
        $user = $this->userWithPermissions(['list-trust-transactions']);
        $client = Client::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/trust-transactions?client_id='.$client->id)->assertOk();
    }

    public function test_permission_is_still_required_on_an_entitled_feature(): void
    {
        $this->usePlan('professional');
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)->getJson('/api/v1/trust-transactions')->assertStatus(403)->assertJsonMissing(['code' => 'entitlement_required']);
    }

    public function test_seat_limit_blocks_creating_one_more_user(): void
    {
        config(['entitlements.plans.starter.limits.users' => 2]);
        $this->usePlan('starter');
        $admin = $this->userWithPermissions(['create-users']);   // seat 1
        User::factory()->create();                   // seat 2

        $this->actingAs($admin)->postJson('/api/v1/users', [])
            ->assertStatus(403)
            ->assertJson(['code' => 'entitlement_required', 'limit' => 'users']);
    }

    public function test_seat_limit_allows_creating_while_below_it(): void
    {
        config(['entitlements.plans.starter.limits.users' => 3]);
        $this->usePlan('starter');
        $admin = $this->userWithPermissions(['create-users']);
        User::factory()->create();

        $code = $this->actingAs($admin)->postJson('/api/v1/users', [])->json('code');

        $this->assertNotSame('entitlement_required', $code, 'reached the controller (422 validation is fine)');
    }

    public function test_soft_deleted_users_do_not_use_a_seat(): void
    {
        config(['entitlements.plans.starter.limits.users' => 2]);
        $this->usePlan('starter');
        $admin = $this->userWithPermissions(['create-users']);
        User::factory()->create()->delete();

        $this->assertTrue(app(Entitlements::class)->within('users'));
    }

    public function test_entitlements_endpoint_reports_plan_limits_and_usage(): void
    {
        $this->usePlan('starter');
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)->getJson('/api/v1/entitlements')->assertOk()
            ->assertJsonPath('plan', 'starter')
            ->assertJsonPath('features.trust_accounting', null)
            ->assertJsonPath('features.payments', true)
            ->assertJsonPath('usage.users', 1);
    }

    public function test_entitlements_endpoint_requires_login(): void
    {
        $this->getJson('/api/v1/entitlements')->assertStatus(401);
    }

    /** "No domain code branches on deployment mode": only infrastructure may read the mode. */
    public function test_domain_code_never_branches_on_deployment_mode(): void
    {
        $allowed = [
            'app/Tenancy/', 'app/Providers/AppServiceProvider.php', 'app/Http/Middleware/ResolveTenant.php',
            'app/Observability/UsageMetrics.php', 'app/Entitlements/ConfiguredPlanSource.php', 'app/Console/Commands/Tenants',
            // deployment tooling: which DB holds the release log / whether to migrate the control plane
            'app/Support/ReleaseLog.php', 'app/Console/Commands/AppUpgrade.php',
            // platform console / per-tenant backup: operate on the fleet, so they must know the mode
            'app/Http/Middleware/PlatformHost.php', 'app/Console/Commands/RunBackup.php', 'app/Console/Commands/SupportExpire.php', 'app/Console/Commands/RetentionRun.php', 'app/Console/Commands/SecretsCheck.php', 'app/Console/Commands/WebhooksDeliver.php', 'app/Console/Commands/BillingRun.php', 'app/Console/Commands/SecretsRotate.php', 'app/Status/Health.php',
            // TEN-11: catalog:sync runs once here or once per firm
            'app/Console/Commands/CatalogSync.php',
            // TEN-8: schema:drift checks this database or every firm's
            'app/Console/Commands/SchemaDriftCommand.php',
            // ENG-21: exports:prune sweeps this database or every firm's
            'app/Console/Commands/ExportsPrune.php',
            // ENG-22: documents:prune-uploads sweeps this database or every firm's
            'app/Console/Commands/DocumentsPruneUploads.php',
            // ENG-19: search:reindex rebuilds this database's indexes or every firm's
            'app/Console/Commands/SearchReindex.php',
            // ENG-26: warehouse:export aggregates this firm or every firm, with its registry row
            'app/Console/Commands/WarehouseExport.php',
            // ENG-27: capacity:report reads this server or every cell's
            'app/Console/Commands/CapacityReport.php',
            // TEN-12: documents:relocate moves this database's documents or every firm's
            'app/Console/Commands/DocumentsRelocate.php',
            // DATA-11: audits:archive archives this database's audit trail or every firm's
            'app/Console/Commands/AuditsArchive.php',
            // PLAT-9: eject/import runs against a single install or inside a tenant, so it must know which
            'app/Portability/PortabilityRunner.php',
            // identity/login flow is a tenancy concern, not an entitlement decision
            'app/Http/Controllers/APIController.php', 'app/Http/Controllers/ProfileController.php',
            // PLAT-13: self-service signup exists only on the SaaS platform host, like the console
            'app/Signup/SignupService.php', 'app/Http/Middleware/SignupHost.php',
            // FUN-16: calendar:sync runs in this database or every firm's, like webhooks:deliver
            'app/Console/Commands/CalendarSyncCommand.php',
        ];

        $offenders = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app')));
        foreach ($it as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace(base_path().'/', '', $file->getPathname());
            if (collect($allowed)->contains(fn ($a) => str_starts_with($rel, $a))) {
                continue;
            }
            if (preg_match('/isMulti\(|tenancy\.mode|TENANCY_MODE|entitlements\.single_tenant_plan/', file_get_contents($file->getPathname()))) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders, 'domain code must ask Entitlements, not the deployment mode');
    }

    public function test_entitlement_denials_are_not_reported_as_errors(): void
    {
        $this->assertFalse(app(Handler::class)->shouldReport(EntitlementException::feature('x')));
        $this->assertTrue(app(Handler::class)->shouldReport(new \RuntimeException('real error')));
    }
}
