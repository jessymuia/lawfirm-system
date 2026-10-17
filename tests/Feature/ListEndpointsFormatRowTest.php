<?php

namespace Tests\Feature;

use App\Models\CourtType;
use App\Models\ExpenseCategory;
use App\Models\HearingType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * Real bug this suite exists to catch, found the hard way against a live
 * MySQL instance (not reproducible via php -l, static analysis, or any
 * check against the schema — this is a pure PHP runtime visibility rule):
 *
 * Every list endpoint's row formatter was declared `private function
 * formatRow(...)`, then handed to paginatedOrFull() as a callable array
 * — `[$this, 'formatRow']`. That callable gets invoked by Laravel's
 * internal Illuminate\Support\Collection::map()/transform(), which lives
 * in a completely unrelated class. PHP's visibility rules mean a
 * `private` method is only invocable from the exact class that declares
 * it — calling it from outside (even via a callable array naming the
 * right object) throws, and Laravel's base Controller::__call() turns
 * that into "Method ...::formatRow does not exist." on every single
 * list endpoint, with a 500 response.
 *
 * Calling $this->formatRow($row) directly from inside the same
 * controller (e.g. from store()/update(), to format a single freshly
 * created/updated record) stays inside the class and was never affected
 * — which is exactly why this bug hid behind working create/update
 * responses while every list view silently 500'd.
 *
 * The fix: formatRow must be `public`. This suite hits every affected
 * endpoint, both with and without ?page=, since both paginatedOrFull()
 * branches (Collection::map() and Collection::transform()) have the
 * identical externally-invoked-callable problem.
 */
class ListEndpointsFormatRowTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    /**
     * @dataProvider listEndpoints
     */
    public function test_list_endpoint_does_not_500_when_paginated(string $uri): void
    {
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->getJson($uri.'?page=1&per_page=10');

        $response->assertStatus(200);
        $this->assertIsArray($response->json('data.data') ?? $response->json('data'));
    }

    /**
     * @dataProvider listEndpoints
     */
    public function test_list_endpoint_is_paginated_even_without_pagination_params(string $uri): void
    {
        // ENG-16: there is no "no ?page= means the whole table" branch any more. Every list answers one page in
        // Laravel's paginator shape, and per_page can never exceed 100.
        $admin = $this->adminUser();

        $response = $this->actingAs($admin)->getJson($uri);
        $response->assertStatus(200);
        $this->assertIsArray($response->json('data.data'));
        $this->assertSame(1, $response->json('data.current_page'));
        $this->assertNotNull($response->json('data.last_page'));

        $this->assertSame(100, $this->getJson($uri.'?per_page=100000')->json('data.per_page'));
        $this->assertSame(1, $this->getJson($uri.'?per_page=0')->json('data.per_page'));
    }

    public function test_the_full_table_branch_is_gone(): void
    {
        $src = file_get_contents(app_path('Http/Controllers/Controller.php'));
        $body = substr($src, strpos($src, 'function paginatedOrFull'));

        $this->assertStringNotContainsString("has('page')", $body);
        $this->assertStringNotContainsString('->get()->map(', $body);
    }

    /**
     * @dataProvider optionEndpoints
     */
    public function test_dropdown_options_are_capped_and_searchable(string $uri, string $model): void
    {
        $admin = $this->adminUser();
        $model::query()->forceDelete();
        foreach (range(1, 3) as $i) {
            $model::create(['name' => "Option {$i}"]);
        }
        $model::create(['name' => 'Something else']);

        $all = $this->actingAs($admin)->getJson($uri)->assertOk();
        $this->assertCount(4, $all->json('data'));
        $this->assertSame(['id', 'name'], array_keys($all->json('data.0')));
        $this->assertFalse($all->json('truncated'));
        $this->assertCount(3, $this->getJson($uri.'?search=Option')->json('data'));
    }

    public static function optionEndpoints(): array
    {
        return [
            'court types' => ['/api/v1/courttypes/options', CourtType::class],
            'hearing types' => ['/api/v1/hearingtypes/options', HearingType::class],
            'expense categories' => ['/api/v1/expense-categories/options', ExpenseCategory::class],
        ];
    }

    public static function listEndpoints(): array
    {
        return [
            'clients' => ['/api/v1/clients'],
            'users' => ['/api/v1/users'],
            'court types' => ['/api/v1/courttypes'],
            'courts' => ['/api/v1/courts'],
            'cases' => ['/api/v1/cases'],
            'tasks' => ['/api/v1/tasks'],
            'expenses' => ['/api/v1/expenses'],
            'expense categories' => ['/api/v1/expense-categories'],
            'hearings' => ['/api/v1/hearings'],
            'hearing types' => ['/api/v1/hearingtypes'],
            'invoices' => ['/api/v1/invoices'],
            'time entries' => ['/api/v1/time-entries'],
            'payments' => ['/api/v1/payments'],
            'roles' => ['/api/v1/roles'],
        ];
    }
}
