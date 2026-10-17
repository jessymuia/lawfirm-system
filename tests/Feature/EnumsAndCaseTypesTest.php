<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Enums\MatterStatus;
use App\Enums\PaymentStatus;
use App\Jobs\BackfillCaseTypes;
use App\Models\Cases;
use App\Models\CaseType;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TrustTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** DATA-12: invalid states cannot be written, and matter types are a list, not free text. */
class EnumsAndCaseTypesTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    private function refused(callable $write, string $message): void
    {
        try {
            $write();
            $this->fail('accepted an invalid value');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_no_code_path_can_save_a_value_outside_its_enum(): void
    {
        $user = User::factory()->create();
        $this->refused(fn () => Task::create(['title' => 'x', 'description' => 'x', 'assigned_to' => $user->id, 'due_date' => '2026-10-10',
            'priority' => 'urgent', 'task_status' => 'pending']), 'tasks.priority cannot be "urgent": it is one of high, medium, low.');

        $task = Task::create(['title' => 'x', 'description' => 'x', 'assigned_to' => $user->id, 'due_date' => '2026-10-10', 'priority' => 'low', 'task_status' => 'pending']);
        $this->refused(fn () => $task->update(['task_status' => 'done']), 'tasks.task_status cannot be "done"');

        $matter = Cases::factory()->create();
        $this->refused(fn () => $matter->forceFill(['lifecycle_status' => 'archived'])->save(), 'cases.lifecycle_status cannot be "archived"');

        $invoice = Invoice::create(['case_id' => $matter->id, 'client_id' => $matter->client_id, 'invoice_date' => '2026-10-01', 'invoice_due_date' => '2026-10-31', 'workflow_status' => 'draft']);
        $this->refused(fn () => $invoice->forceFill(['workflow_status' => 'sent'])->save(), 'invoices.workflow_status cannot be "sent"');
        $this->refused(fn () => Payment::create(['invoice_id' => $invoice->id, 'amount' => 1, 'payment_date' => '2026-10-02', 'method' => 'bitcoin', 'received_by' => $user->id]),
            'payments.method cannot be "bitcoin"');
        $this->refused(fn () => TrustTransaction::create(['client_id' => $matter->client_id, 'type' => 'loan', 'amount' => 1, 'description' => 'x', 'recorded_by' => $user->id]),
            'trust_transactions.type cannot be "loan"');
    }

    public function test_the_api_validates_with_the_same_enums(): void
    {
        $this->actingAs($this->userWithPermissions([P::CREATE_TASK]));
        $this->postJson('/api/v1/tasks', ['title' => 'x', 'description' => 'x', 'assigned_to' => User::factory()->create()->id, 'due_date' => '10/10/2026',
            'priority' => 'urgent', 'status' => 'pending'])->assertUnprocessable()->assertJsonValidationErrors('priority');
    }

    public function test_matter_lifecycle_and_payment_status_come_from_the_enums(): void
    {
        $this->assertSame(['open' => ['closed', 'appeal', 'settled'], 'appeal' => ['open', 'settled', 'closed'], 'closed' => ['open'], 'settled' => []],
            Cases::transitions());
        $this->assertSame([], MatterStatus::Settled->next(), 'settled is terminal');
        $this->assertSame([PaymentStatus::Unpaid, PaymentStatus::PartiallyPaid, PaymentStatus::Paid, PaymentStatus::Unpaid],
            [PaymentStatus::for(100, 0), PaymentStatus::for(100, 40), PaymentStatus::for(100, 100), PaymentStatus::for(0, 0)]);
    }

    public function test_matter_types_are_one_list_whatever_the_spelling(): void
    {
        $client = Client::factory()->create();
        $a = Cases::factory()->create(['client_id' => $client->id, 'case_type' => 'Civil']);
        $b = Cases::factory()->create(['client_id' => $client->id, 'case_type' => '  civil ']);
        $c = Cases::factory()->create(['client_id' => $client->id, 'case_type' => 'CIVIL']);
        $d = Cases::factory()->create(['client_id' => $client->id, 'case_type' => 'Land  and Environment']);

        $this->assertSame(['Civil', 'Land and Environment'], CaseType::orderBy('name')->pluck('name')->all());
        $this->assertSame([$a->case_type_id, $a->case_type_id], [$b->case_type_id, $c->case_type_id]);
        $this->assertSame(['Civil', 'Civil'], [$b->fresh()->case_type, $c->fresh()->case_type], 'the list\'s spelling');

        $this->actingAs($this->userWithPermissions([P::LIST_CASES]));
        $this->assertSame(['Civil', 'Land and Environment'], array_column($this->getJson('/api/v1/case-types/options')->assertOk()->json('data'), 'name'));
        CaseType::where('name', 'Civil')->update(['archived_at' => now()]);
        $this->assertSame(['Land and Environment'], array_column($this->getJson('/api/v1/case-types/options')->json('data'), 'name'), 'archived types are not offered');
        $shape = (new \App\Http\Resources\V1\MatterResource($d->fresh()))->toArray(request());
        $this->assertSame([$d->case_type_id, 'Land and Environment'], [$shape['case_type_id'], $shape['case_type']]);
    }

    public function test_existing_matters_are_backfilled_once(): void
    {
        $matter = Cases::factory()->create(['case_type' => 'Criminal']);
        DB::table('cases')->insert(array_merge(DB::table('cases')->where('id', $matter->id)->first(['client_id', 'assigned_to', 'court_id', 'description', 'police_station', 'opposing_party', 'start_date']) ? (array) DB::table('cases')->where('id', $matter->id)->first(['client_id', 'assigned_to', 'court_id', 'description', 'police_station', 'opposing_party', 'start_date']) : [],
            ['case_number' => 'OLD/1', 'case_type' => 'criminal', 'case_type_id' => null, 'lifecycle_status' => 'open', 'created_at' => now(), 'updated_at' => now()]));
        DB::table('cases')->insert(array_merge((array) DB::table('cases')->where('id', $matter->id)->first(['client_id', 'assigned_to', 'court_id', 'description', 'police_station', 'opposing_party', 'start_date']),
            ['case_number' => 'OLD/2', 'case_type' => 'Family', 'case_type_id' => null, 'lifecycle_status' => 'open', 'created_at' => now(), 'updated_at' => now()]));

        $this->assertSame(2, (new BackfillCaseTypes)->handle(app(\App\Matters\CaseTypes::class)));
        $this->assertSame($matter->case_type_id, (int) DB::table('cases')->where('case_number', 'OLD/1')->value('case_type_id'), 'joins the existing type');
        $this->assertSame(['Criminal', 'Family'], CaseType::orderBy('name')->pluck('name')->all());
        $this->assertSame(0, (new BackfillCaseTypes)->handle(app(\App\Matters\CaseTypes::class)), 'nothing left to fill');
    }

    public function test_the_database_refuses_an_unknown_priority_on_mysql(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The priority CHECK constraint is added on MySQL/MariaDB; SQLite relies on the model guard.');
        }
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('tasks')->insert(['title' => 'x', 'description' => 'x', 'assigned_to' => User::factory()->create()->id, 'due_date' => '2026-10-10',
            'priority' => 'urgent', 'task_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    }
}
