<?php

namespace Tests\Feature;

use App\Constants\ModulePermissions as P;
use App\Models\Cases;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** UI-13: the firm's name, colour, logo and letterhead, in the app and on its documents. */
class BrandingTest extends TestCase
{
    use CreatesTestUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['app.name' => 'Wairimu & Partners Advocates', 'tenancy.active_files_disk' => 'local']);
    }

    private function admin()
    {
        return $this->actingAs($this->userWithPermissions([P::MANAGE_BRANDING]));
    }

    public function test_before_anything_is_set_the_firm_shows_its_name_and_the_default_colour(): void
    {
        $this->getJson('/api/v1/branding')->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'success', 'data' => ['name' => 'Wairimu & Partners Advocates', 'color' => '#0e6b50', 'logo_url' => null]]);
        $this->get('/api/v1/branding/logo')->assertNotFound();
    }

    public function test_an_administrator_sets_name_colour_and_letterhead(): void
    {
        $this->actingAs($this->userWithPermissions([]))->putJson('/api/v1/branding', ['color' => '#123456'])->assertForbidden();

        $this->admin()->putJson('/api/v1/branding', ['color' => 'blue'])->assertUnprocessable()->assertJsonValidationErrors('color');
        $this->putJson('/api/v1/branding', ['name' => 'Wairimu & Partners', 'color' => '#7a1f2b',
            'letterhead' => ['address' => '4th Floor, Bishop Road, Nairobi', 'email' => 'office@wairimu.law', 'tax_pin' => 'P051234567X', 'evil' => 'x'],
            'invoice_footer' => "Pay to: KCB, A/C 1234\nTerms: 30 days"])->assertOk()->assertJsonPath('data.letterhead.address', '4th Floor, Bishop Road, Nairobi');

        $this->getJson('/api/v1/branding')->assertJsonPath('data.name', 'Wairimu & Partners')->assertJsonPath('data.color', '#7a1f2b');
        $settings = $this->getJson('/api/v1/branding/settings')->assertOk()->json('data');
        $this->assertArrayNotHasKey('evil', $settings['letterhead'], 'only the known letterhead lines are kept');
        $this->assertNull($settings['letterhead']['phone']);

        // the installed app takes the firm's name and colour too
        $this->get('/manifest.webmanifest')->assertJsonPath('name', 'Wairimu & Partners')->assertJsonPath('theme_color', '#7a1f2b');
    }

    public function test_the_logo_is_an_image_kept_on_the_firms_disk(): void
    {
        $png = UploadedFile::fake()->image('logo.png', 400, 120);
        $url = $this->admin()->post('/api/v1/branding/logo', ['logo' => $png], ['Accept' => 'application/json'])->assertOk()->json('data.logo_url');
        $this->assertSame('/api/v1/branding/logo?v=1', $url);
        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringContainsString('branding/logo-', $files[0]);

        $res = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame(Storage::disk('local')->get($files[0]), $res->getContent());

        // a new logo replaces the old file and changes the URL, so browsers fetch it again
        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('logo2.jpg', 300, 100)], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.logo_url', '/api/v1/branding/logo?v=2');
        $this->assertCount(1, Storage::disk('local')->allFiles());

        // SVG can carry script: refused, whatever it is called (a real file, typed from its bytes as an upload is)
        $path = tempnam(sys_get_temp_dir(), 'svg');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $svg = new UploadedFile($path, 'logo.png', 'image/png', null, true);
        $this->post('/api/v1/branding/logo', ['logo' => $svg], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('logo');
        $this->assertSame('/api/v1/branding/logo?v=2', $this->getJson('/api/v1/branding')->json('data.logo_url'), 'the previous logo stays');

        $this->deleteJson('/api/v1/branding/logo')->assertOk()->assertJsonPath('data.logo_url', null);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_invoices_carry_the_letterhead_logo_and_footer(): void
    {
        $this->admin()->putJson('/api/v1/branding', ['name' => 'Wairimu & Partners', 'color' => '#7a1f2b',
            'letterhead' => ['address' => 'Bishop Road, Nairobi', 'tax_pin' => 'P051234567X'], 'invoice_footer' => 'Pay to: KCB, A/C 1234'])->assertOk();
        $this->post('/api/v1/branding/logo', ['logo' => UploadedFile::fake()->image('logo.png', 200, 60)], ['Accept' => 'application/json'])->assertOk();

        $matter = Cases::factory()->create();
        $invoice = Invoice::create(['case_id' => $matter->id, 'client_id' => $matter->client_id, 'invoice_date' => '2026-10-01', 'invoice_due_date' => '2026-10-31',
            'workflow_status' => 'submitted']);
        $html = view('exports.invoice', ['invoice' => $invoice->fresh()])->render();

        foreach (['Wairimu &amp; Partners', 'Bishop Road, Nairobi', 'PIN P051234567X', 'Pay to: KCB, A/C 1234', 'border-bottom: 3px solid #7a1f2b', 'src="data:image/png;base64,'] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }
    }

    public function test_letterhead_text_cannot_inject_markup(): void
    {
        $this->admin()->putJson('/api/v1/branding', ['name' => '<script>x</script>', 'letterhead' => ['address' => '<img src=x onerror=alert(1)>']])->assertOk();
        $matter = Cases::factory()->create();
        $invoice = Invoice::create(['case_id' => $matter->id, 'client_id' => $matter->client_id, 'invoice_date' => '2026-10-01', 'invoice_due_date' => '2026-10-31',
            'workflow_status' => 'submitted']);
        $html = view('exports.invoice', ['invoice' => $invoice->fresh()])->render();
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }
}
