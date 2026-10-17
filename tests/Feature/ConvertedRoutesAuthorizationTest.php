<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * ENG-11: clients / cases / invoices / payments / trust / users / roles and the other modules are authorized at the route, before the
 * controller. A user with no permissions must get 403 on every one of them,
 * and a user holding exactly the mapped permission must get past the gate.
 */
class ConvertedRoutesAuthorizationTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    /** @return array<string, array{0:string,1:string,2:string}> method, uri, permission */
    public static function routes(): array
    {
        $r = [
            ['GET', 'clients', 'list-clients'], ['GET', 'clientsDropDown', 'list-clients'],
            ['GET', 'clients-check-phone', 'list-clients'], ['POST', 'clients', 'create-clients'],
            ['POST', 'clients/bulk-delete', 'delete-clients'], ['POST', 'clients/1/restore', 'delete-clients'],
            ['GET', 'clients/1', 'list-clients'], ['PUT', 'clients/1', 'update-clients'], ['DELETE', 'clients/1', 'delete-clients'],
            ['GET', 'cases', 'list-cases'], ['GET', 'casesDropDown', 'list-cases'], ['GET', 'cases-check-number', 'list-cases'],
            ['POST', 'cases', 'create-cases'], ['POST', 'cases/bulk-delete', 'delete-cases'], ['POST', 'cases/1/restore', 'delete-cases'],
            ['GET', 'cases/1/activity', 'list-cases'], ['PUT', 'cases/1/status', 'update-cases'],
            ['GET', 'cases/1', 'list-cases'], ['PUT', 'cases/1', 'update-cases'], ['DELETE', 'cases/1', 'delete-cases'],
            ['GET', 'preview', 'view-documents'],
            ['GET', 'invoices', 'list-invoice'], ['POST', 'invoices', 'create-invoice'], ['GET', 'invoices/1', 'list-invoice'],
            ['PUT', 'invoices/1', 'update-invoice'], ['PUT', 'send-to-admin/1', 'update-invoice'], ['GET', 'preview-invoice/1', 'list-invoice'],
            ['POST', 'invoiceItems', 'create-invoice'], ['GET', 'invoiceItems/1', 'list-invoice'],
            ['PUT', 'invoiceItems/1', 'update-invoice'], ['DELETE', 'invoiceItems/1', 'delete-invoice'],
            ['GET', 'users', 'list-users'], ['POST', 'users', 'create-users'], ['PUT', 'users/1', 'update-users'], ['DELETE', 'users/1', 'delete-users'],
            ['GET', 'rolesDropDown', 'list-roles'],
            ['GET', 'tasks', 'list-task'], ['POST', 'tasks', 'create-task'], ['PUT', 'tasks/1', 'update-task'], ['DELETE', 'tasks/1', 'delete-task'],
            ['POST', 'tasks/bulk-delete', 'delete-task'], ['POST', 'tasks/1/restore', 'delete-task'],
            ['GET', 'expenses', 'list-expenses'], ['GET', 'expenses/1', 'list-expenses'], ['POST', 'expenses', 'create-expenses'],
            ['PUT', 'expenses/1', 'update-expenses'], ['DELETE', 'expenses/1', 'delete-expenses'], ['POST', 'expenses/bulk-delete', 'delete-expenses'],
            ['GET', 'hearings', 'list-hearings'], ['POST', 'hearings', 'create-hearings'], ['PUT', 'hearings/1', 'update-hearings'],
            ['DELETE', 'hearings/1', 'delete-hearings'], ['POST', 'hearings/bulk-delete', 'delete-hearings'], ['POST', 'hearings/1/restore', 'delete-hearings'],
            ['POST', 'documents', 'create-documents'], ['GET', 'documents/1', 'list-documents'], ['DELETE', 'documents/1', 'delete-documents'],
            ['GET', 'documents/1/versions', 'list-documents'],
            ['GET', 'time-entries', 'list-time-entries'], ['POST', 'time-entries', 'create-time-entries'], ['PUT', 'time-entries/1', 'update-time-entries'],
            ['DELETE', 'time-entries/1', 'delete-time-entries'], ['POST', 'time-entries/generate-invoice', 'create-invoice'],
            ['GET', 'payments', 'list-payments'], ['GET', 'payments/1', 'list-payments'], ['POST', 'payments', 'create-payments'], ['DELETE', 'payments/1', 'delete-payments'],
            ['GET', 'trust-transactions', 'list-trust-transactions'], ['POST', 'trust-transactions', 'create-trust-transactions'],
            ['PUT', 'trust-transactions/1/void', 'void-trust-transactions'],
            ['GET', 'dashboard', 'view-dashboard'],
            ['GET', 'export/cases', 'list-cases'], ['GET', 'export/expenses', 'list-expenses'], ['GET', 'export/invoices', 'list-invoice'],
            ['GET', 'export/invoices/1/pdf', 'list-invoice'], ['GET', 'export/firm-data', 'export-firm-data'],
            ['GET', 'roles', 'list-roles'], ['POST', 'roles', 'create-roles'], ['PUT', 'roles/x', 'update-roles'], ['DELETE', 'roles/x', 'delete-roles'],
            ['GET', 'permissions', 'list-permissions'], ['GET', 'permissions/x', 'list-roles'],
        ];

        return array_combine(array_map(fn ($x) => "$x[0] /$x[1]", $r), $r);
    }

    /** @dataProvider routes */
    public function test_user_without_permission_is_refused_at_the_route(string $method, string $uri, string $perm): void
    {
        $user = $this->userWithPermissions([]);

        $this->actingAs($user)->json($method, "/api/v1/{$uri}")->assertStatus(403);
    }

    /** @dataProvider routes */
    public function test_user_with_the_mapped_permission_passes_the_route_gate(string $method, string $uri, string $perm): void
    {
        $user = $this->userWithPermissions([$perm]);

        // Empty payloads/unknown ids may 404/422, but never the route's 403.
        $status = $this->actingAs($user)->json($method, "/api/v1/{$uri}")->getStatusCode();
        $this->assertNotSame(403, $status, "{$method} /{$uri} refused a user holding {$perm}");
    }

    /** @dataProvider routes */
    public function test_gate_is_declared_on_the_route_itself(string $method, string $uri, string $perm): void
    {
        $route = app('router')->getRoutes()->match(
            Request::create("/api/v1/{$uri}", $method)
        );

        $this->assertContains("authz:permission,{$perm}", $route->gatherMiddleware());
    }
}
