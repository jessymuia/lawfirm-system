<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * SEC-7 regression: created_by/status used to be listed in every model's
 * $fillable, so a client could spoof who "created" a record (or, on User,
 * even its own created_at/updated_at/deleted_at) simply by including
 * those keys in a create/update payload. This suite proves:
 *
 *  - created_by is always the authenticated user, never whatever the
 *    request body claims, even when the request body includes a
 *    different created_by/id/status value;
 *  - status is never movable via ordinary request input;
 *  - none of this broke normal create/update behaviour for the fields
 *    that ARE still legitimately fillable.
 */
class MassAssignmentTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_client_store_ignores_spoofed_created_by_id_and_status(): void
    {
        $actualUser = $this->userWithPermissions(['create-clients']);
        $advocate = $this->userWithPermissions([]);
        $someoneElse = User::factory()->create();

        $response = $this->actingAs($actualUser)->postJson('/api/v1/clients', [
            'name' => 'Jane Doe',
            'phone_number' => '712345678',
            'address' => '123 Main St',
            'advocate' => $advocate->id,
            // Attempted spoofing — none of these are legitimate client input:
            'id' => 999999,
            'created_by' => $someoneElse->id,
            'status' => 0,
        ]);

        $response->assertStatus(201);

        $client = Client::query()->latest('id')->firstOrFail();

        $this->assertNotEquals(999999, $client->id);
        $this->assertEquals($actualUser->id, $client->created_by);
        $this->assertNotEquals($someoneElse->id, $client->created_by);
        // status keeps its DB column default (1) regardless of the
        // spoofed 0 in the request body.
        $this->assertEquals(1, $client->status);
    }

    public function test_client_update_cannot_change_created_by_or_status_via_request_body(): void
    {
        $creator = $this->userWithPermissions(['create-clients']);
        $editor = $this->userWithPermissions(['create-clients', 'update-clients']);
        $intruder = User::factory()->create();

        $client = $this->actingAs($creator)->postJson('/api/v1/clients', [
            'name' => 'Original Name',
            'phone_number' => '722334455',
            'address' => '456 Side St',
            'advocate' => $creator->id,
        ]);
        $client->assertStatus(201);
        $clientId = Client::query()->latest('id')->firstOrFail()->id;

        $response = $this->actingAs($editor)->putJson("/api/v1/clients/{$clientId}", [
            'name' => 'Updated Name',
            'phone_number' => '722334455',
            'address' => '456 Side St',
            'advocate' => $creator->id,
            'created_by' => $intruder->id,
            'status' => 0,
        ]);

        $response->assertStatus(200);

        $updated = Client::findOrFail($clientId);
        $this->assertEquals($creator->id, $updated->created_by, 'created_by must never change on update.');
        $this->assertNotEquals($intruder->id, $updated->created_by);
        $this->assertEquals(1, $updated->status);
        $this->assertEquals('Updated Name', $updated->name);
    }

    public function test_user_store_ignores_spoofed_audit_timestamps(): void
    {
        // Note: unlike every other model, User deliberately does NOT
        // auto-stamp created_by — the users table has no such column
        // (see User::booted()'s comment). The spoofing this test guards
        // against is therefore just the timestamp/soft-delete fields,
        // which are real columns on `users`.
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->postJson('/api/v1/users', [
            'name' => 'New Hire',
            'phone_number' => '733445566',
            'email' => 'new.hire@example.test',
            'department' => 'Litigation',
            'hire_date' => now()->format('d/m/Y'),
            // Attempted spoofing:
            'created_at' => '2000-01-01 00:00:00',
            'updated_at' => '2000-01-01 00:00:00',
            'deleted_at' => now()->toDateTimeString(),
        ]);

        $response->assertStatus(201);

        $newUser = User::where('email', 'new.hire@example.test')->firstOrFail();

        $this->assertNull($newUser->deleted_at, 'A spoofed deleted_at must never soft-delete a brand-new user.');
        $this->assertNotEquals('2000-01-01 00:00:00', $newUser->created_at->format('Y-m-d H:i:s'));
    }
}
