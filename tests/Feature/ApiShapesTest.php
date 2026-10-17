<?php

namespace Tests\Feature;

use App\Api\TypeScriptTypes;
use App\Constants\ModulePermissions as P;
use App\Http\Resources\V1;
use App\Models\Cases;
use App\Models\Client;
use App\Models\Court;
use App\Models\CourtType;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Hearing;
use App\Models\HearingType;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Role;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * ENG-15: every v1 Resource declares its shape (SHAPE); the web app's TypeScript types are generated from it. This keeps
 * both honest: a resource that answers a key or type its SHAPE does not say fails here, and so does a stale types file.
 */
class ApiShapesTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    /** @return array<class-string, object> a real record per resource */
    private function samples(): array
    {
        $user = $this->userWithPermissions([P::DELETE_INVOICES]);
        $user->forceFill(['department' => 'Litigation', 'hire_date' => '2024-01-15', 'timekeeper_class' => 'PT'])->save();
        $this->actingAs($user);
        $client = Client::factory()->create(['advocate_id' => $user->id, 'extra_phone_number' => '0711000000', 'address' => 'Nairobi']);
        $matter = Cases::factory()->create(['client_id' => $client->id, 'assigned_to' => $user->id, 'start_date' => '2026-01-01']);
        $invoice = Invoice::create(['case_id' => $matter->id, 'client_id' => $client->id, 'invoice_date' => '2026-02-01', 'invoice_due_date' => '2026-03-01', 'workflow_status' => 'draft']);
        $invoice->items()->create(['description' => 'Advice', 'quantity' => 1, 'rate' => 1000, 'tax' => 0, 'total_amount' => 1000]);
        $invoice->refreshTotals();
        $invoice->forceFill(['workflow_status' => 'submitted'])->save();
        $category = ExpenseCategory::factory()->create();

        return [
            V1\ClientResource::class => $client,
            V1\MatterResource::class => $matter->fresh(),
            V1\CourtResource::class => Court::factory()->create(),
            V1\CourtTypeResource::class => CourtType::factory()->create(),
            V1\HearingTypeResource::class => HearingType::factory()->create(),
            V1\ExpenseCategoryResource::class => $category,
            V1\HearingResource::class => Hearing::factory()->create(['case_id' => $matter->id, 'start_time' => '09:30', 'duration_minutes' => 30]),
            V1\TaskResource::class => Task::factory()->create(['assigned_to' => $user->id]),
            V1\InvoiceResource::class => $invoice->fresh(),
            V1\PaymentResource::class => Payment::create(['invoice_id' => $invoice->id, 'amount' => 400, 'payment_date' => '2026-02-10', 'method' => 'cash', 'received_by' => $user->id]),
            V1\TimeEntryResource::class => TimeEntry::create(['case_id' => $matter->id, 'user_id' => $user->id, 'description' => 'Drafting', 'date' => '2026-02-02', 'hours' => 1.5, 'hourly_rate' => 9000, 'billable' => true])->fresh(),
            V1\ExpenseResource::class => Expense::create(['case_id' => $matter->id, 'date' => '2026-02-03', 'amount' => 250, 'category' => $category->id, 'description' => 'Filing fee', 'vendor' => 'Court registry', 'payment_method' => 'cash', 'user_id' => $user->id])->fresh(),
            V1\UserResource::class => $user->fresh(),
            V1\RoleResource::class => Role::create(['name' => 'shape-test-role']),
        ];
    }

    private function fits(string $type, mixed $v): bool
    {
        if ($v === null) {
            return str_starts_with($type, '?');
        }

        return match (ltrim($type, '?')) {
            'int' => is_int($v),
            'string' => is_string($v),
            'bool' => is_bool($v),
            'decimal' => is_string($v) && is_numeric($v) || is_int($v) || is_float($v),
            'date' => is_string($v) && (bool) preg_match('#^\d{2}/\d{2}/\d{4}$#', $v),
            'int[]' => is_array($v) && array_is_list($v) && array_filter($v, 'is_int') === $v,
            default => false,
        };
    }

    public function test_every_resource_answers_exactly_its_declared_shape(): void
    {
        $samples = $this->samples();
        $resources = array_map(fn ($f) => 'App\\Http\\Resources\\V1\\'.basename($f, '.php'), glob(app_path('Http/Resources/V1/*Resource.php')));
        $this->assertEqualsCanonicalizing($resources, array_keys($samples), 'every resource has a sample here');

        foreach ($samples as $class => $model) {
            $json = json_decode(json_encode((new $class($model))->toArray(request())), true);
            $this->assertSame(array_keys($class::SHAPE), array_keys($json), "{$class}: keys (and their order) differ from SHAPE");
            foreach ($class::SHAPE as $key => $type) {
                $this->assertTrue($this->fits($type, $json[$key]), "{$class}.{$key}: ".var_export($json[$key], true)." is not {$type}");
            }
        }
    }

    public function test_the_generated_typescript_is_up_to_date(): void
    {
        $this->assertSame(0, Artisan::call('api:types', ['--check' => true]), 'run php artisan api:types and commit '.TypeScriptTypes::FILE);
        foreach (array_merge(...array_values(TypeScriptTypes::shapes())) as $type) {
            TypeScriptTypes::ts($type);   // every type in every shape is one the generator knows
        }
        $this->assertStringContainsString("export interface Invoice {\n    id: number;", (string) file_get_contents(base_path(TypeScriptTypes::FILE)));
    }
}
