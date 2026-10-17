<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\CreatesTestUsers;
use Tests\TestCase;

class CaseCrudTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        $client = Client::factory()->create();
        $advocate = User::factory()->create();
        $advocate->assignRole(Role::findOrCreate('advocate', 'web'));
        $court = Court::factory()->create();

        return array_merge([
            'case_number' => 'CN'.rand(100000, 999999),
            'description' => 'Test case description',
            'client_id' => $client->id,
            'assigned_to' => $advocate->id,
            'start_date' => '01/06/2026',
            'case_type' => 'civil',
            'police_station' => 'Kilimani',
            'court_id' => $court->id,
            'opposing_party' => 'Jane Doe',
        ], $overrides);
    }

    public function test_create_case(): void
    {
        $user = $this->userWithPermissions(['create-cases', 'list-cases']);

        $response = $this->actingAs($user)->postJson('/api/v1/cases', $this->payload([
            'case_number' => 'CN-CREATE-TEST',
        ]));

        // ENG-4: store() returns the created resource with 201 Created.
        $response->assertStatus(201);
        $this->assertDatabaseHas('cases', ['case_number' => 'CN-CREATE-TEST']);
    }

    public function test_case_number_must_be_unique(): void
    {
        // DATA-5: DB-level unique constraint on cases.case_number.
        $user = $this->userWithPermissions(['create-cases', 'list-cases']);
        $existing = Cases::factory()->create(['case_number' => 'DUPLICATE-001']);

        $response = $this->actingAs($user)->postJson('/api/v1/cases', $this->payload([
            'case_number' => 'DUPLICATE-001',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('case_number');
    }

    public function test_update_case(): void
    {
        $user = $this->userWithPermissions(['create-cases', 'update-cases', 'list-cases']);
        $case = Cases::factory()->create();

        $response = $this->actingAs($user)->putJson("/api/v1/cases/{$case->id}", $this->payload([
            'case_number' => $case->case_number,
            'description' => 'Updated description',
        ]));

        $response->assertStatus(200);
        $this->assertEquals('Updated description', $case->fresh()->description);
    }

    public function test_delete_case_is_a_soft_delete(): void
    {
        // DATA-7: destroy() was previously an empty stub for cases.
        $user = $this->userWithPermissions(['create-cases', 'delete-cases', 'list-cases']);
        $case = Cases::factory()->create();

        $response = $this->actingAs($user)->deleteJson("/api/v1/cases/{$case->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('cases', ['id' => $case->id]);
    }

    public function test_case_lifecycle_status_transitions_are_enforced(): void
    {
        // FUN-6: only valid transitions should be allowed.
        $user = $this->userWithPermissions(['update-cases']);
        $case = Cases::factory()->create(['lifecycle_status' => 'settled']);

        // 'settled' is a terminal state — no transitions out of it.
        $response = $this->actingAs($user)->putJson("/api/v1/cases/{$case->id}/status", [
            'lifecycle_status' => 'open',
        ]);

        $response->assertStatus(422);
        $this->assertEquals('settled', $case->fresh()->lifecycle_status);
    }

    public function test_valid_case_lifecycle_transition_succeeds(): void
    {
        $user = $this->userWithPermissions(['update-cases']);
        $case = Cases::factory()->create(['lifecycle_status' => 'open']);

        $response = $this->actingAs($user)->putJson("/api/v1/cases/{$case->id}/status", [
            'lifecycle_status' => 'closed',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('closed', $case->fresh()->lifecycle_status);
    }
}
