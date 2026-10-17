<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\TrustTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

class TrustAccountingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_deposit_increases_balance(): void
    {
        $user = $this->userWithPermissions(['create-trust-transactions', 'list-trust-transactions']);
        $client = Client::factory()->create();

        $this->actingAs($user)->postJson('/api/v1/trust-transactions', [
            'client_id' => $client->id,
            'type' => 'deposit',
            'amount' => 500,
            'description' => 'Initial retainer',
        ])->assertStatus(200);

        $this->assertEquals(500, TrustTransaction::balanceForClient($client->id));
    }

    public function test_disbursement_cannot_overdraw_the_trust_balance(): void
    {
        // The core invariant: a client's trust balance must never go
        // negative. Deposit 100, then attempt to disburse 200.
        $user = $this->userWithPermissions(['create-trust-transactions']);
        $client = Client::factory()->create();

        TrustTransaction::post($client->id, null, 'deposit', 100, 'Retainer', null, $user->id);

        $response = $this->actingAs($user)->postJson('/api/v1/trust-transactions', [
            'client_id' => $client->id,
            'type' => 'disbursement',
            'amount' => 200,
            'description' => 'Court filing fee',
        ]);

        $response->assertStatus(422);
        $this->assertEquals(100, TrustTransaction::balanceForClient($client->id));
    }

    public function test_disbursement_up_to_the_balance_succeeds(): void
    {
        $user = $this->userWithPermissions(['create-trust-transactions']);
        $client = Client::factory()->create();

        TrustTransaction::post($client->id, null, 'deposit', 100, 'Retainer', null, $user->id);

        $this->actingAs($user)->postJson('/api/v1/trust-transactions', [
            'client_id' => $client->id,
            'type' => 'disbursement',
            'amount' => 100,
            'description' => 'Court filing fee',
        ])->assertStatus(200);

        $this->assertEquals(0, TrustTransaction::balanceForClient($client->id));
    }

    public function test_voiding_a_deposit_is_blocked_if_funds_already_disbursed(): void
    {
        $user = $this->userWithPermissions(['create-trust-transactions', 'void-trust-transactions']);
        $client = Client::factory()->create();

        $deposit = TrustTransaction::post($client->id, null, 'deposit', 100, 'Retainer', null, $user->id);
        TrustTransaction::post($client->id, null, 'disbursement', 100, 'Filing fee', null, $user->id);

        $response = $this->actingAs($user)->putJson("/api/v1/trust-transactions/{$deposit->id}/void");

        $response->assertStatus(422);
        $this->assertNotNull($deposit->fresh());
        $this->assertNull($deposit->fresh()->voided_at);
    }

    public function test_voided_transactions_are_excluded_from_balance(): void
    {
        $user = $this->userWithPermissions(['create-trust-transactions', 'void-trust-transactions']);
        $client = Client::factory()->create();

        $deposit = TrustTransaction::post($client->id, null, 'deposit', 100, 'Retainer', null, $user->id);
        $this->assertEquals(100, TrustTransaction::balanceForClient($client->id));

        $this->actingAs($user)->putJson("/api/v1/trust-transactions/{$deposit->id}/void")->assertStatus(200);

        $this->assertEquals(0, TrustTransaction::balanceForClient($client->id));
    }

    public function test_ledger_is_scoped_per_client_not_pooled_firm_wide(): void
    {
        $user = $this->userWithPermissions(['create-trust-transactions']);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        TrustTransaction::post($clientA->id, null, 'deposit', 500, 'Retainer', null, $user->id);

        $this->assertEquals(500, TrustTransaction::balanceForClient($clientA->id));
        $this->assertEquals(0, TrustTransaction::balanceForClient($clientB->id));
    }
}
