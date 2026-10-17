<?php

namespace Tests\Feature;

use App\Billing\Billing;
use App\Constants\ModulePermissions as P;
use App\Entitlements\Entitlements;
use App\Models\Cases;
use App\Models\Client;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** PLAT-13: the firm's own subscription screen, an ended trial, and the getting-started checklist. */
class SubscriptionAndOnboardingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_a_trial_firm_sees_its_trial_goes_read_only_when_it_ends_and_can_still_choose_a_plan(): void
    {
        $admin = $this->userWithPermissions([P::MANAGE_SUBSCRIPTION, P::CREATE_CLIENTS, P::LIST_CLIENTS]);
        $tenants = app(TenantManager::class);
        if (! $tenants->isMulti()) {
            // a self-hosted install has a license, not a subscription
            $this->actingAs($admin)->getJson('/api/v1/subscription')->assertNotFound();

            return;
        }

        $tenant = $tenants->current();
        $this->usePlan('trial');
        app(Billing::class)->startTrial($tenant, 'billing@firm.test');
        $client = ['name' => 'Acme Ltd', 'phone_number' => '0711000001', 'address' => 'Nairobi', 'advocate' => $admin->id];

        $this->actingAs($admin)->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.plan', 'trial')
            ->assertJsonPath('data.status', 'trialing')
            ->assertJsonPath('data.trial_days_left', 14)
            ->assertJsonPath('data.trial_expired', false)
            ->assertJsonPath('data.plans.0.plan', 'starter');

        // the trial ends: reads work, writes are refused with their own code, choosing a plan still works
        $tenant->update(['trial_ends_at' => now()->subMinute()]);
        app(Entitlements::class)->forget();
        $this->getJson('/api/v1/clients?page=1')->assertOk()->assertHeader('X-License-Notice');
        $this->postJson('/api/v1/clients', $client)->assertForbidden()->assertJsonPath('code', 'trial_expired');
        $this->getJson('/api/v1/subscription')->assertOk()->assertJsonPath('data.trial_expired', true);

        $this->postJson('/api/v1/subscription/plan', ['plan' => 'trial'])->assertUnprocessable();
        $this->postJson('/api/v1/subscription/plan', ['plan' => 'starter'])->assertOk()->assertJsonPath('data.plan', 'starter')->assertJsonPath('data.trial_expired', false);
        $this->postJson('/api/v1/clients', $client)->assertCreated();
        $this->assertNotNull(DB::connection('control')->table('subscriptions')->where('tenant_id', $tenant->id)->value('converted_at'));
        $this->assertSame(1, DB::table('audits')->where('event', 'subscription_plan_chosen')->count());
    }

    public function test_the_subscription_needs_its_permission(): void
    {
        $u = $this->userWithPermissions([P::LIST_CLIENTS]);
        $this->actingAs($u)->getJson('/api/v1/subscription')->assertForbidden();
        $this->actingAs($u)->postJson('/api/v1/subscription/plan', ['plan' => 'starter'])->assertForbidden();
        $this->actingAs($u)->postJson('/api/v1/subscription/cancel')->assertForbidden();
    }

    public function test_a_firm_can_view_its_invoices_pay_by_card_and_schedule_cancellation(): void
    {
        $admin = $this->userWithPermissions([P::MANAGE_SUBSCRIPTION]);
        $tenants = app(TenantManager::class);
        if (! $tenants->isMulti()) {
            $this->actingAs($admin)->postJson('/api/v1/subscription/cancel')->assertNotFound();

            return;
        }

        config([
            'billing.stripe.secret_key' => 'sk_test_platform',
            'billing.mpesa.consumer_key' => 'key',
            'billing.mpesa.consumer_secret' => 'secret',
            'billing.mpesa.shortcode' => '123456',
            'billing.mpesa.callback_token' => 'callback-token',
            'app.url' => 'https://app.test',
            'tenancy.platform_host' => 'billing.test',
        ]);
        $tenant = $tenants->current();
        app(Billing::class)->subscribe($tenant, 'starter', 'KES', 'manual', 'billing@firm.test');

        $this->actingAs($admin)->getJson('/api/v1/subscription')->assertOk()
            ->assertJsonPath('data.invoices.0.status', 'open')
            ->assertJsonPath('data.invoices.0.amount_due', 500000)
            ->assertJsonPath('data.invoices.0.pay_url', fn ($url) => str_starts_with($url, 'https://billing.test/api/platform/pay/'))
            ->assertJsonPath('data.mpesa_paybill', '123456')
            ->assertJsonPath('data.cancel_at_period_end', false);

        $this->postJson('/api/v1/subscription/cancel')->assertOk()
            ->assertJsonPath('data.cancel_at_period_end', true)
            ->assertJsonPath('data.status', 'active');
        $this->assertTrue((bool) DB::connection('control')->table('subscriptions')->where('tenant_id', $tenant->id)->value('cancel_at_period_end'));
        $this->assertSame(1, DB::table('audits')->where('event', 'subscription_cancel_scheduled')->count());
    }

    public function test_the_checklist_follows_the_firms_own_data_and_can_be_hidden(): void
    {
        $admin = $this->userWithPermissions([P::CREATE_CLIENTS, P::CREATE_CASES, P::CREATE_USERS]);

        $steps = fn () => collect($this->actingAs($admin)->getJson('/api/v1/onboarding')->assertOk()->json('data.steps'))->keyBy('key');
        $s = $steps();
        $this->assertFalse($s['client']['done']);
        $this->assertSame('/clients?new=1', $s['client']['link']);
        $this->assertTrue($this->getJson('/api/v1/onboarding')->json('data.show'));

        $client = Client::factory()->create();
        $s = $steps();
        $this->assertTrue($s['client']['done']);
        $this->assertSame('/cases?new=1&client_id='.$client->id, $s['matter']['link'], 'the matter step opens the new-matter form for the first client');
        $this->assertFalse($s['matter']['done']);

        Cases::factory()->create(['client_id' => $client->id]);
        $this->assertTrue($steps()['matter']['done']);
        $this->assertTrue($steps()['colleague']['done'], 'the factories made more people');

        $this->postJson('/api/v1/onboarding/dismiss')->assertOk();
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.dismissed', true)->assertJsonPath('data.show', false);
    }

    public function test_steps_the_person_may_not_do_are_marked_and_do_not_keep_the_checklist_open(): void
    {
        $clerk = $this->userWithPermissions([P::LIST_CLIENTS]);
        $r = $this->actingAs($clerk)->getJson('/api/v1/onboarding')->assertOk();
        $s = collect($r->json('data.steps'))->keyBy('key');
        $this->assertFalse($s['client']['can']);
        $this->assertFalse($s['matter']['can']);
        $this->assertTrue($s['two_factor']['can']);
        $this->assertTrue($r->json('data.show'), 'two-factor is still theirs to do');
    }
}
