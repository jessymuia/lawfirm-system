<?php

namespace Tests\Feature;

use App\Models\User;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** UI-16: someone in several firms moves between them without signing in again, under each firm's own rules. */
class FirmSwitchTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/firmswitch-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        foreach (['firm-a' => 'jane@example.com', 'firm-b' => 'jane@example.com', 'firm-c' => 'carl@example.com'] as $slug => $email) {
            app(TenantLifecycle::class)->provision(['slug' => $slug, 'name' => strtoupper($slug), 'admin_email' => $email,
                'domain' => "{$slug}.app.test", 'database' => "{$this->dir}/{$slug}.sqlite"]);
        }
        config(['tenancy.mode' => 'multi']);
    }

    protected function tearDown(): void
    {
        config(['tenancy.mode' => 'single']);
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function jane(string $slug): User
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->first(), fn () => User::where('email', 'jane@example.com')->first(), allowInactive: true);
    }

    private function audits(string $slug, string $event): int
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->first(), fn () => DB::table('audits')->where('event', $event)->count(), allowInactive: true);
    }

    /** Jane, signed in at firm A, asks to go to $to; returns the URL she is sent to. */
    private function issue(string $to = 'firm-b'): string
    {
        return $this->actingAsOnHost($this->jane('firm-a'), 'http://firm-a.app.test')
            ->postJson("http://firm-a.app.test/api/v1/firms/{$to}/switch")->assertOk()->json('data.url');
    }

    private function redeem(string $url)
    {
        Auth::forgetGuards();

        return $this->get($url);
    }

    public function test_jane_sees_her_firms_and_moves_to_another_without_signing_in(): void
    {
        $firms = $this->actingAsOnHost($this->jane('firm-a'), 'http://firm-a.app.test')->getJson('http://firm-a.app.test/api/v1/firms')->assertOk()->json('data');
        $this->assertSame([['slug' => 'firm-a', 'name' => 'FIRM-A', 'current' => true], ['slug' => 'firm-b', 'name' => 'FIRM-B', 'current' => false]], $firms);

        $url = $this->issue();
        $this->assertMatchesRegularExpression('#^http://firm-b\.app\.test/switch/[A-Za-z0-9]{48}$#', $url);
        $this->assertSame(1, $this->audits('firm-a', 'firm_switch_issued'), 'recorded in the firm she left');
        $token = basename($url);
        $this->assertSame(1, DB::connection('control')->table('firm_switch_tokens')->where('token_hash', hash('sha256', $token))->count(), 'only the hash is kept');

        $this->redeem($url)->assertRedirect('/');
        $this->assertSame($this->jane('firm-b')->id, Auth::guard('web')->id(), 'signed in as her user in firm B');
        $this->assertSame(1, $this->audits('firm-b', 'firm_switched_in'), 'recorded in the firm she arrived at');
    }

    public function test_a_link_works_once_for_a_minute_and_only_at_its_firm(): void
    {
        $url = $this->issue();
        $this->redeem($url)->assertRedirect('/');
        $this->redeem($url)->assertRedirect('/login?switch=invalid');

        $late = $this->issue();
        $this->travel(61)->seconds();
        $this->redeem($late)->assertRedirect('/login?switch=invalid');

        $this->travelBack();
        $elsewhere = $this->issue();
        $this->redeem(str_replace('firm-b.app.test', 'firm-c.app.test', $elsewhere))->assertRedirect('/login?switch=invalid');
        $this->assertNull(Auth::guard('web')->user());
    }

    public function test_only_her_own_other_active_firms(): void
    {
        $this->actingAsOnHost($this->jane('firm-a'), 'http://firm-a.app.test')->postJson('http://firm-a.app.test/api/v1/firms/firm-c/switch')->assertNotFound();
        $this->postJson('http://firm-a.app.test/api/v1/firms/firm-a/switch')->assertNotFound();
        Tenant::where('slug', 'firm-b')->update(['status' => 'suspended']);
        $this->postJson('http://firm-a.app.test/api/v1/firms/firm-b/switch')->assertNotFound();
        $this->assertSame(['firm-a'], array_column($this->getJson('http://firm-a.app.test/api/v1/firms')->json('data'), 'slug'));
    }

    public function test_a_firm_that_asks_for_more_gets_its_own_sign_in(): void
    {
        $b = Tenant::where('slug', 'firm-b')->first();
        $janeB = $this->jane('firm-b');
        app(TenantManager::class)->runIn($b, fn () => DB::table('user_mfa')->insert(['user_id' => $janeB->id, 'secret' => 'x', 'confirmed_at' => now(),
            'created_at' => now(), 'updated_at' => now()]), allowInactive: true);

        $this->redeem($this->issue())->assertRedirect('/login?switch=mfa');
        $this->assertNull(Auth::guard('web')->user(), 'two-factor at firm B is not skipped');

        // someone removed from firm B since the link was made
        app(TenantManager::class)->runIn($b, fn () => DB::table('user_mfa')->delete(), allowInactive: true);
        $url = $this->issue();
        app(TenantManager::class)->runIn($b, fn () => User::find($janeB->id)->delete(), allowInactive: true);
        $this->redeem($url)->assertRedirect('/login?switch=invalid');
    }
}
