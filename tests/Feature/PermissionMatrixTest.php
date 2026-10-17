<?php

namespace Tests\Feature;

use App\Models\Cases;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * SEC-3 regression guard: this codebase previously had controllers
 * checking the *wrong* permission constant entirely (e.g. hearings and
 * cases both checking a shared, mislabelled "case documents" permission;
 * ExpenseCategoryController checking LIST_INVOICES instead of its own
 * expenses permission). A route responding 200 to a user who does NOT
 * hold that route's own permission is exactly the bug class this suite
 * exists to catch — and it would catch it silently reappearing in a
 * completely different module too, since every module is checked here
 * against every OTHER module's permission, not just its own.
 */
class PermissionMatrixTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    /**
     * @return array<string, array{method: string, url: string, permission: string}>
     */
    private function listRoutes(): array
    {
        return [
            'clients' => ['method' => 'get', 'url' => '/api/v1/clients', 'permission' => 'list-clients'],
            'cases' => ['method' => 'get', 'url' => '/api/v1/cases', 'permission' => 'list-cases'],
            'hearings' => ['method' => 'get', 'url' => '/api/v1/hearings', 'permission' => 'list-hearings'],
            'expenses' => ['method' => 'get', 'url' => '/api/v1/expenses', 'permission' => 'list-expenses'],
            'invoices' => ['method' => 'get', 'url' => '/api/v1/invoices', 'permission' => 'list-invoice'],
            'courts' => ['method' => 'get', 'url' => '/api/v1/courts', 'permission' => 'list-court'],
        ];
    }

    public function test_every_list_route_requires_exactly_its_own_permission(): void
    {
        $routes = $this->listRoutes();

        foreach ($routes as $moduleName => $route) {
            // A user with NO permissions at all must be rejected.
            $noPermsUser = $this->userWithPermissions([]);
            $response = $this->actingAs($noPermsUser)->json($route['method'], $route['url']);
            $this->assertEquals(
                403,
                $response->status(),
                "Expected 403 for {$moduleName} with no permissions, got {$response->status()}"
            );

            // A user with every OTHER module's permission, but not this
            // one, must still be rejected — this is precisely the check
            // that catches a controller accidentally validating against
            // the wrong module's permission constant.
            $otherPermissions = collect($routes)
                ->except($moduleName)
                ->pluck('permission')
                ->values()
                ->all();
            $wrongPermsUser = $this->userWithPermissions($otherPermissions);
            $response = $this->actingAs($wrongPermsUser)->json($route['method'], $route['url']);
            $this->assertEquals(
                403,
                $response->status(),
                "Expected 403 for {$moduleName} with every OTHER module's permissions, got {$response->status()}. ".
                'This means the route is not checking its own permission constant.'
            );

            // A user with exactly this module's permission must succeed.
            $correctPermsUser = $this->userWithPermissions([$route['permission']]);
            $response = $this->actingAs($correctPermsUser)->json($route['method'], $route['url']);
            $this->assertEquals(
                200,
                $response->status(),
                "Expected 200 for {$moduleName} with its own permission, got {$response->status()}"
            );
        }
    }

    public function test_hearings_and_cases_do_not_share_a_permission_constant(): void
    {
        // SEC-3's specific historical bug: HearingController and
        // CasesController both checked ModulePermissions::LIST_CASE_DOCUMENTS
        // (a mislabelled constant actually valued 'list-cases'). A user
        // with only 'list-hearings' must NOT be able to list cases, and
        // vice versa.
        $hearingsOnlyUser = $this->userWithPermissions(['list-hearings']);
        $this->actingAs($hearingsOnlyUser)->getJson('/api/v1/cases')->assertStatus(403);

        $casesOnlyUser = $this->userWithPermissions(['list-cases']);
        $this->actingAs($casesOnlyUser)->getJson('/api/v1/hearings')->assertStatus(403);
    }

    public function test_expense_categories_do_not_check_invoice_permission(): void
    {
        // SEC-3's specific historical bug: ExpenseCategoryController::index
        // checked ModulePermissions::LIST_INVOICES instead of an expenses
        // permission. A user with only invoice permissions must not be
        // able to list expense categories.
        $invoiceOnlyUser = $this->userWithPermissions(['list-invoice']);

        $this->actingAs($invoiceOnlyUser)
            ->getJson('/api/v1/expense-categories')
            ->assertStatus(403);
    }

    public function test_create_update_delete_each_require_their_own_permission(): void
    {
        // Spot-check the write actions on one representative module
        // (cases) beyond just list, since SEC-3/SEC-11-class bugs
        // historically hit create/update endpoints with zero permission
        // check at all, not just wrong-constant checks on index().
        $case = Cases::factory()->create();

        $noPerms = $this->userWithPermissions([]);
        $this->actingAs($noPerms)->postJson('/api/v1/cases', [])->assertStatus(403);
        $this->actingAs($noPerms)->putJson("/api/v1/cases/{$case->id}", [])->assertStatus(403);
        $this->actingAs($noPerms)->deleteJson("/api/v1/cases/{$case->id}")->assertStatus(403);

        $wrongPerms = $this->userWithPermissions(['list-clients', 'create-clients', 'update-clients', 'delete-clients']);
        $this->actingAs($wrongPerms)->postJson('/api/v1/cases', [])->assertStatus(403);
        $this->actingAs($wrongPerms)->putJson("/api/v1/cases/{$case->id}", [])->assertStatus(403);
        $this->actingAs($wrongPerms)->deleteJson("/api/v1/cases/{$case->id}")->assertStatus(403);
    }

    // ---- ENG-11: deny by default -----------------------------------------------------------------------------------

    /** @return array<class-string, \App\Policies\ModulePolicy> every model with its policy */
    private function policies(): array
    {
        $out = [];
        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');
            $policy = \Illuminate\Support\Facades\Gate::getPolicyFor($class);
            $this->assertInstanceOf(\App\Policies\ModulePolicy::class, $policy, "{$class} has no policy (add app/Policies/".class_basename($class).'Policy.php).');
            $out[$class] = $policy;
        }

        return $out;
    }

    public function test_every_model_has_a_policy_that_denies_a_user_without_permissions(): void
    {
        $nobody = $this->userWithPermissions([]);
        $gate = \Illuminate\Support\Facades\Gate::forUser($nobody);

        foreach ($this->policies() as $class => $policy) {
            foreach (['viewAny', 'create'] as $ability) {
                $this->assertFalse($gate->allows($ability, $class), "{$class}: {$ability} allowed without any permission");
            }
            foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
                $this->assertFalse($gate->allows($ability, new $class), "{$class}: {$ability} allowed without any permission");
            }
        }
    }

    public function test_each_ability_needs_exactly_its_mapped_permission_and_unmapped_abilities_are_refused_to_everyone(): void
    {
        $admin = $this->adminUser();   // every permission the app defines
        $abilities = ['viewAny', 'view', 'create', 'update', 'delete', 'restore'];

        foreach ($this->policies() as $class => $policy) {
            foreach ($abilities as $ability) {
                $target = in_array($ability, ['viewAny', 'create'], true) ? $class : new $class;
                $permission = $policy->permissionFor($ability);
                $holder = $permission !== null ? $this->userWithPermissions([$permission]) : null;
                if ($target instanceof \App\Models\FirmExport) {
                    $target->forceFill(['requested_by' => $holder?->id]);   // an export belongs to whoever asked for it
                }
                if ($permission === null) {
                    if ($class === \App\Models\Invoice::class && $ability === 'delete') {
                        continue;   // own-draft rule, covered below
                    }
                    $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($admin)->allows($ability, $target), "{$class}: unmapped {$ability} allowed");

                    continue;
                }
                $this->assertTrue(\Spatie\Permission\Models\Permission::where('name', $permission)->exists(), "{$class}: {$ability} maps to unknown permission {$permission}");
                $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($holder)->allows($ability, $target), "{$class}: {$ability} refused to a holder of {$permission}");
            }
            $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($admin)->allows('forceDelete', new $class), "{$class}: forceDelete allowed");
            $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($admin)->allows('somethingNobodyDefined', $class), "{$class}: an undefined ability was allowed");
        }
    }

    public function test_a_model_without_a_policy_is_refused(): void
    {
        $orphan = new class extends \Illuminate\Database\Eloquent\Model {};

        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($this->adminUser())->allows('view', $orphan));
    }

    public function test_record_abilities_follow_matter_access_and_ethical_walls(): void
    {
        config(['matters.access' => 'restricted']);
        $user = $this->userWithPermissions(['list-cases', 'update-cases', 'list-hearings']);
        $mine = Cases::factory()->create(['assigned_to' => $user->id]);
        $theirs = Cases::factory()->create();
        $gate = \Illuminate\Support\Facades\Gate::forUser($user);

        $this->assertTrue($gate->allows('view', $mine));
        $this->assertTrue($gate->allows('update', $mine));
        $this->assertFalse($gate->allows('view', $theirs), 'a matter outside the access rule');
        $this->assertFalse($gate->allows('view', new \App\Models\Hearing(['case_id' => $theirs->id])), 'records follow their matter');
        $this->assertTrue($gate->allows('view', new \App\Models\Hearing(['case_id' => $mine->id])));
    }

    public function test_invoice_delete_is_any_invoice_with_the_permission_otherwise_only_ones_own_draft_and_a_refusal_is_a_404(): void
    {
        $clerk = $this->userWithPermissions(['create-invoice', 'list-invoice']);
        $colleague = $this->userWithPermissions(['create-invoice', 'list-invoice']);
        $draft = function ($user) {
            $case = Cases::factory()->create();

            return \App\Models\Invoice::findOrFail($this->actingAs($user)->postJson('/api/v1/invoices', [
                'case_id' => $case->id, 'client_id' => $case->client_id, 'invoice_date' => '01/10/2026', 'invoice_due_date' => '31/10/2026',
            ])->assertCreated()->json('data.id'));
        };
        $own = $draft($clerk);
        $ownSent = $draft($clerk);
        \Illuminate\Support\Facades\DB::table('invoices')->where('id', $ownSent->id)->update(['workflow_status' => 'submitted']);
        $ownSent->refresh();
        $other = $draft($colleague);

        $gate = \Illuminate\Support\Facades\Gate::forUser($clerk);
        $this->assertTrue($gate->allows('delete', $own));
        $this->assertFalse($gate->allows('delete', $ownSent));
        $this->assertSame(404, $gate->inspect('delete', $other)->status());
        $this->assertTrue(\Illuminate\Support\Facades\Gate::forUser($this->userWithPermissions(['delete-invoice']))->allows('delete', $other));
        $this->assertFalse(\Illuminate\Support\Facades\Gate::forUser($this->userWithPermissions([]))->allows('deleteAny', \App\Models\Invoice::class));

        $this->actingAs($clerk)->deleteJson("/api/v1/invoices/{$other->id}")->assertNotFound();
        $this->actingAs($clerk)->deleteJson("/api/v1/invoices/{$own->id}")->assertOk();
        $this->actingAs($this->userWithPermissions([]))->postJson('/api/v1/invoices/bulk-delete', ['ids' => [$ownSent->id]])->assertForbidden();
    }

    public function test_a_policy_route_naming_no_model_fails_closed(): void
    {
        $request = \Illuminate\Http\Request::create('/x');
        $request->setUserResolver(fn () => $this->adminUser());

        $this->expectException(\LogicException::class);
        (new \App\Http\Middleware\AuthorizeRoute)->handle($request, fn () => response('should not run'), 'policy', 'view', 'NoSuchModel');
    }
}
