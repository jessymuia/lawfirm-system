<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/**
 * The full-firm export is the "you can always get your data out" guarantee (also
 * relied on by PLAT-2's read-only mode). It used to 500 on every successful call
 * because its return type omitted BinaryFileResponse.
 */
class FirmDataExportTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    public function test_full_firm_export_returns_a_zip_of_the_data(): void
    {
        $user = $this->userWithPermissions(['export-firm-data']);
        \App\Models\Client::factory()->create(['name' => 'Wanjiku & Sons']);

        $response = $this->actingAs($user)->get('/api/v1/export/firm-data');

        $response->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file) === true);
        $this->assertNotFalse($zip->locateName('clients.csv'));
        $this->assertStringContainsString('Wanjiku & Sons', $zip->getFromName('clients.csv'));
        $zip->close();
    }

    public function test_full_firm_export_needs_its_permission(): void
    {
        $this->actingAs($this->userWithPermissions(['list-clients']))->get('/api/v1/export/firm-data')->assertStatus(403);
    }
}
