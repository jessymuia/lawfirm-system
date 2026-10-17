<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PLAT-4: the plan catalogue as data the operator can manage (control plane, new table only).
 *
 * The table starts EMPTY on purpose: while it is empty the plans in config/billing.php and config/entitlements.php apply
 * unchanged. The first time staff edit or add a plan, the built-in plans are copied in (App\Billing\PlanStore::seedIfEmpty)
 * and from then on the table is the source of truth for the plans it holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('control')->create('plans', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();           // what subscriptions and tenants.plan refer to; never changes
            $t->string('name', 80);
            $t->string('description', 300)->nullable();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->json('prices');                         // {"KES": 2500000, "USD": 19900}: monthly, minor units
            $t->json('features');                       // {"trust_accounting": true, ...}
            $t->json('limits');                         // {"users": 25, "storage_bytes": ..., "audit_online_months": 36}; null = unlimited
            $t->timestamp('archived_at')->nullable();   // archived: kept for existing subscribers, not offered to new ones
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('control')->dropIfExists('plans');
    }
};
