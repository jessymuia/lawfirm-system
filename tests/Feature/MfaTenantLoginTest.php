<?php

namespace Tests\Feature;

use App\Models\User;
use App\Security\Mfa;
use App\Security\Totp;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** SEC-12 in SaaS mode: the second factor belongs to the firm's user row, so firms are independent. */
class MfaTenantLoginTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/mfat-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite', 'tenancy.mode' => 'multi', 'tenancy.base_domain' => 'app.test',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function provision(string $slug): void
    {
        app(TenantLifecycle::class)->provision(['slug' => $slug, 'name' => $slug, 'admin_email' => 'owner@shared.test', 'admin_password' => 'a-long-password-1', 'domain' => "{$slug}.app.test", 'database' => "{$this->dir}/{$slug}.sqlite"]);
    }

    private function in(string $slug, \Closure $fn)
    {
        return app(TenantManager::class)->runIn(Tenant::where('slug', $slug)->first(), $fn, allowInactive: true);
    }

    private function login(string $slug)
    {
        RateLimiter::clear('owner@shared.test|127.0.0.1');
        app('auth')->forgetGuards();
        app('auth')->shouldUse('web');

        return $this->postJson("http://{$slug}.app.test/api/v1/login", ['email' => 'owner@shared.test', 'password' => 'a-long-password-1']);
    }

    public function test_mfa_in_one_firm_does_not_change_the_same_persons_login_to_another(): void
    {
        $this->provision('alpha');
        $this->provision('beta');

        // enrol in alpha only
        $secret = $this->in('alpha', function () {
            $u = User::where('email', 'owner@shared.test')->first();
            $m = new Mfa;
            $s = $m->begin($u)['secret'];
            $m->confirm($u, Totp::code($s, Totp::currentStep()));

            return $s;
        });

        // alpha: password -> challenge -> second step -> signed in
        $r = $this->login('alpha')->assertOk()->assertJsonPath('message', 'mfa_required');
        $this->postJson('http://alpha.app.test/api/v1/login/mfa', ['challenge' => $r->json('data.challenge'), 'code' => Totp::code($secret, Totp::currentStep() + 1)])
            ->assertOk()->assertJsonPath('message', 'success');

        // beta: untouched, plain login
        $this->login('beta')->assertOk()->assertJsonPath('message', 'success');

        // a challenge issued by alpha means nothing in beta
        $r = $this->login('alpha')->assertJsonPath('message', 'mfa_required');
        $this->postJson('http://beta.app.test/api/v1/login/mfa', ['challenge' => $r->json('data.challenge'), 'code' => Totp::code($secret, Totp::currentStep() + 1)])->assertUnauthorized();
    }
}
