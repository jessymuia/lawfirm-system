<?php

namespace Tests\Feature;

use App\Api\OpenApiSpec;
use App\Api\TypeScriptClient;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** ENG-12: the staff API's OpenAPI document and typed client are generated from the code and cannot drift from it. */
class OpenApiTest extends TestCase
{
    public function test_the_committed_document_and_client_are_up_to_date(): void
    {
        $this->assertSame(0, Artisan::call('api:openapi', ['--check' => true]), 'run php artisan api:openapi and commit '.OpenApiSpec::FILE.' and '.TypeScriptClient::FILE);
    }

    public function test_a_route_added_without_regenerating_fails_the_check(): void
    {
        Route::middleware(['api', 'authz:authenticated'])->post('/api/v1/drift-probe', fn () => null);
        $this->assertSame(1, Artisan::call('api:openapi', ['--check' => true]));
        $this->assertStringContainsString(OpenApiSpec::FILE.' is out of date', Artisan::output());
    }

    public function test_every_staff_route_is_described_once_with_its_authorization(): void
    {
        $spec = OpenApiSpec::build();
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/') && ! str_starts_with($r->uri(), 'api/v1/public'));
        $operations = collect($spec['paths'])->flatMap(fn ($methods) => array_values($methods));
        $this->assertSame($routes->sum(fn ($r) => count(array_diff($r->methods(), ['HEAD']))), $operations->count());
        $this->assertSame($operations->count(), $operations->pluck('operationId')->unique()->count(), 'operation ids are unique');
        $this->assertSame('permission,create-invoice', $spec['paths']['/invoices']['post']['x-authz']);
        $kinds = $operations->pluck('x-authz')->map(fn ($a) => strtok($a, ','))->unique()->sort()->values()->all();
        $this->assertSame(['authenticated', 'permission', 'policy', 'portal-session', 'public'], $kinds);
        $this->assertSame('portal-session', $spec['paths']['/portal/me']['get']['x-authz']);
        $this->assertSame('public', $spec['paths']['/login']['post']['x-authz']);
    }

    public function test_request_bodies_and_answers_come_from_form_requests_and_resources(): void
    {
        $spec = OpenApiSpec::build();
        $store = $spec['paths']['/invoices']['post'];
        $body = $store['requestBody']['content']['application/json']['schema'];
        $this->assertSame(['case_id', 'client_id', 'invoice_date', 'invoice_due_date'], $body['required']);
        $this->assertSame(['type' => 'integer'], $body['properties']['case_id']);
        $this->assertSame('#/components/schemas/Invoice', $store['responses']['200']['content']['application/json']['schema']['allOf'][1]['properties']['data']['$ref']);

        $list = $spec['paths']['/invoices']['get']['responses']['200']['content']['application/json']['schema']['allOf'][1]['properties']['data'];
        $this->assertSame('#/components/schemas/Paginated', $list['allOf'][0]['$ref']);

        $logo = $spec['paths']['/branding/logo']['post']['requestBody']['content'];
        $this->assertSame(['type' => 'string', 'format' => 'binary', 'description' => 'At most 1024 KB'], $logo['multipart/form-data']['schema']['properties']['logo']);
        $this->assertSame(['high', 'medium', 'low'], $spec['paths']['/tasks']['post']['requestBody']['content']['application/json']['schema']['properties']['priority']['enum']);

        $this->assertSame(['integer'], [$spec['paths']['/finance/exchange-rates/{id}']['delete']['parameters'][0]['schema']['type']]);
        $this->assertArrayHasKey('Invoice', $spec['components']['schemas']);
    }

    public function test_the_client_has_a_typed_function_for_every_operation(): void
    {
        $spec = OpenApiSpec::build();
        $client = TypeScriptClient::render($spec);
        foreach ($spec['paths'] as $path => $methods) {
            foreach ($methods as $op) {
                $this->assertStringContainsString("export const {$op['operationId']} = (", $client, $path);
            }
        }
        $this->assertStringContainsString('export const invoiceStore = (body: InvoiceStoreBody, config?: AxiosRequestConfig): Promise<ApiResponse<Invoice>>', $client);
        $this->assertStringContainsString('export const casesDestroy = (caseId: ', $client, 'reserved words are renamed');
    }
}
