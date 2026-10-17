<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** PLAT-4: subscriptions, invoices, payments and provider-event idempotency (control plane, new tables only). */
return new class extends Migration
{
    private function schema()
    {
        return Schema::connection('control');
    }

    public function up(): void
    {
        $this->schema()->create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $t->string('plan', 40);
            $t->string('currency', 3);
            $t->string('provider', 16)->default('manual');             // manual | stripe | mpesa
            $t->string('status', 16)->default('active');               // trialing | active | past_due | canceled
            $t->string('billing_email')->nullable();
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->timestamp('trial_ends_at')->nullable();
            $t->boolean('cancel_at_period_end')->default(false);
            $t->bigInteger('credit_balance')->default(0);               // minor units owed TO the customer
            $t->boolean('suspended_by_billing')->default(false);
            $t->timestamps();
        });

        $this->schema()->create('billing_invoices', function (Blueprint $t) {
            $t->id();
            $t->string('number', 24)->unique();
            $t->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('kind', 16);                                     // period | adjustment
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->string('currency', 3);
            $t->bigInteger('subtotal');
            $t->bigInteger('credit_applied')->default(0);
            $t->bigInteger('tax')->default(0);
            $t->bigInteger('total');
            $t->bigInteger('amount_paid')->default(0);
            $t->string('status', 12)->default('open');                 // open | paid | void
            $t->date('issued_at');
            $t->date('due_at');
            $t->unsignedTinyInteger('dunning_step')->default(0);
            $t->timestamps();
            // one period invoice per subscription per period: generation is idempotent
            $t->unique(['subscription_id', 'kind', 'period_start']);
        });

        $this->schema()->create('billing_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $t->string('kind', 24);                                     // base | overage_users | overage_storage | proration_credit | proration_charge | credit
            $t->string('description');
            $t->bigInteger('quantity')->default(1);
            $t->bigInteger('unit_amount');
            $t->bigInteger('amount');
            $t->timestamps();
        });

        $this->schema()->create('billing_payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained('billing_invoices')->cascadeOnDelete();
            $t->string('provider', 16);
            $t->string('provider_ref', 120);
            $t->bigInteger('amount');
            $t->string('currency', 3);
            $t->timestamp('received_at');
            $t->timestamps();
            $t->unique(['provider', 'provider_ref']);                  // a replayed webhook can never pay twice
        });

        $this->schema()->create('billing_events', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 16);
            $t->string('event_id', 120);
            $t->string('type', 60)->nullable();
            $t->string('outcome', 24);                                  // applied | unmatched | ignored | rejected
            $t->text('detail')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'event_id']);
        });

        $this->schema()->create('billing_counters', function (Blueprint $t) {
            $t->string('name', 24)->primary();
            $t->unsignedBigInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        foreach (['billing_counters', 'billing_events', 'billing_payments', 'billing_invoice_lines', 'billing_invoices', 'subscriptions'] as $t) {
            $this->schema()->dropIfExists($t);
        }
    }
};
