<?php

namespace Tests\Feature;

use App\Models\Cases;
use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * SEC-7: every store/update endpoint must reject invalid payloads with a
 * proper 422 and a real error bag, not silently accept bad data or crash
 * with a 500. DATA-5: uniqueness must be enforced on update, not just
 * create (the original bug: case_number/phone uniqueness was checked
 * only when creating a record).
 */
class ValidationTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_case_store_rejects_empty_payload_with_422_and_field_errors(): void
    {
        $user = $this->userWithPermissions(['create-cases']);

        $response = $this->actingAs($user)->postJson('/api/v1/cases', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'case_number', 'description', 'client_id', 'assigned_to',
            'start_date', 'police_station', 'court_id', 'opposing_party',
        ]);
    }

    public function test_case_store_rejects_nonexistent_client_id(): void
    {
        $user = $this->userWithPermissions(['create-cases']);

        $response = $this->actingAs($user)->postJson('/api/v1/cases', [
            'case_number' => 'CN-VALID-001',
            'description' => 'A case',
            'client_id' => 999999, // does not exist
            'assigned_to' => 999999,
            'start_date' => '01/06/2026',
            'police_station' => 'Kilimani',
            'court_id' => 999999,
            'opposing_party' => 'Jane Doe',
            'case_type' => 'civil',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['client_id', 'assigned_to', 'court_id']);
    }

    public function test_client_store_rejects_invalid_payload(): void
    {
        $user = $this->userWithPermissions(['create-clients']);

        $response = $this->actingAs($user)->postJson('/api/v1/clients', [
            'name' => '', // required
            'phone_number' => '', // required
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['name', 'phone_number', 'address', 'advocate']);
    }

    public function test_client_phone_uniqueness_enforced_on_create(): void
    {
        $user = $this->userWithPermissions(['create-clients']);
        $existing = Client::factory()->create(['phone_number' => '0712345678']);

        $response = $this->actingAs($user)->postJson('/api/v1/clients', [
            'name' => 'New Client',
            'phone_number' => '0712345678',
            'address' => 'Nairobi',
            'advocate' => $existing->advocate_id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone_number');
    }

    public function test_client_phone_uniqueness_enforced_on_update_not_just_create(): void
    {
        // DATA-5's exact original bug: uniqueness was only checked on
        // create. Two existing clients; attempting to update the second
        // to have the first's phone number must be rejected.
        $user = $this->userWithPermissions(['create-clients', 'update-clients']);
        $clientA = Client::factory()->create(['phone_number' => '0711111111']);
        $clientB = Client::factory()->create(['phone_number' => '0722222222']);

        $response = $this->actingAs($user)->putJson("/api/v1/clients/{$clientB->id}", [
            'name' => $clientB->name,
            'phone_number' => '0711111111', // clientA's number
            'address' => $clientB->address,
            'advocate' => $clientB->advocate_id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('phone_number');
    }

    public function test_client_can_keep_its_own_phone_number_on_update(): void
    {
        // The uniqueness rule must exclude the record being updated from
        // the check against itself, or every update would fail.
        $user = $this->userWithPermissions(['create-clients', 'update-clients']);
        $client = Client::factory()->create(['phone_number' => '0733333333']);

        $response = $this->actingAs($user)->putJson("/api/v1/clients/{$client->id}", [
            'name' => 'Updated Name',
            'phone_number' => '0733333333', // unchanged
            'address' => $client->address,
            'advocate' => $client->advocate_id,
        ]);

        $response->assertStatus(200);
    }

    public function test_case_number_uniqueness_enforced_on_update_not_just_create(): void
    {
        $user = $this->userWithPermissions(['create-cases', 'update-cases']);
        $caseA = Cases::factory()->create(['case_number' => 'CN-EXISTING']);
        $caseB = Cases::factory()->create(['case_number' => 'CN-OTHER']);

        $response = $this->actingAs($user)->putJson("/api/v1/cases/{$caseB->id}", [
            'case_number' => 'CN-EXISTING', // caseA's number
            'description' => $caseB->description,
            'client_id' => $caseB->client_id,
            'assigned_to' => $caseB->assigned_to,
            'start_date' => '01/06/2026',
            'police_station' => $caseB->police_station,
            'court_id' => $caseB->court_id,
            'opposing_party' => $caseB->opposing_party,
            'case_type' => $caseB->case_type,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('case_number');
    }

    public function test_hearing_store_rejects_invalid_date_format(): void
    {
        $user = $this->userWithPermissions(['create-hearings']);
        $case = Cases::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/hearings', [
            'case_id' => $case->id,
            'court_id' => $case->court_id,
            'hearing_date' => '2026-06-01', // wrong format — should be d/m/Y
            'hearing_type' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('hearing_date');
    }

    public function test_expense_store_rejects_negative_amount(): void
    {
        $user = $this->userWithPermissions(['create-expenses']);
        $case = Cases::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/v1/expenses', [
            'case_id' => $case->id,
            'expense_date' => '01/06/2026',
            'amount' => -50,
            'category' => 1,
            'description' => 'Test',
            'vendor' => 'Test vendor',
            'payment_method' => 'cash',
            'user_id' => $user->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('amount');
    }
}
