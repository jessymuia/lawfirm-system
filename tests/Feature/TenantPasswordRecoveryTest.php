<?php

namespace Tests\Feature;

use App\Mail\ForgetPassword;
use App\Models\User;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-5: password recovery for control-plane identities in multi-tenant mode. */
class TenantPasswordRecoveryTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private string $dir;

    private string $originalDefault;

    private string $password;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/tenrec-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");

        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);

        $this->password = $this->provision('firm-a', 'jane@example.com')['admin_password'];
        $this->provision('firm-b', 'jane@example.com');
        $this->provision('firm-c', 'carl@example.com');

        config(['tenancy.mode' => 'multi']);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function provision(string $slug, string $email): array
    {
        return app(TenantLifecycle::class)->provision([
            'slug' => $slug, 'name' => $slug, 'admin_email' => $email,
            'domain' => "{$slug}.app.test", 'database' => "{$this->dir}/{$slug}.sqlite",
        ]);
    }

    private function recover(string $slug, string $email)
    {
        RateLimiter::clear('pwd-recovery|'.strtolower($email).'|127.0.0.1');

        return $this->postJson("http://{$slug}.app.test/api/v1/password-recovery", ['email' => $email]);
    }

    private function sentToken(): ?string
    {
        $token = null;
        Mail::assertSent(ForgetPassword::class, function ($m) use (&$token) {
            $token = $m->token;

            return true;
        });

        return $token;
    }

    private function reset(string $slug, string $email, string $token, string $password = 'Brand-new-pass-1')
    {
        return $this->postJson("http://{$slug}.app.test/api/v1/password-reset", [
            'email' => $email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password,
        ]);
    }

    private function login(string $slug, string $email, string $password)
    {
        RateLimiter::clear(strtolower($email).'|127.0.0.1');

        return $this->postJson("http://{$slug}.app.test/api/v1/login", ['email' => $email, 'password' => $password]);
    }

    public function test_recovery_mails_a_link_on_the_firms_own_domain(): void
    {
        $this->recover('firm-a', 'jane@example.com')->assertOk();

        Mail::assertSent(ForgetPassword::class, fn ($m) => $m->baseUrl === 'http://firm-a.app.test/' && $m->hasTo('jane@example.com'));
    }

    public function test_response_is_identical_and_no_mail_for_unknown_or_non_member_emails(): void
    {
        $known = $this->recover('firm-a', 'jane@example.com');
        Mail::fake(); // reset

        $unknown = $this->recover('firm-a', 'nobody@example.com');
        $nonMember = $this->recover('firm-c', 'jane@example.com'); // real person, not in firm-c

        $this->assertSame($known->getContent(), $unknown->getContent());
        $this->assertSame($known->getContent(), $nonMember->getContent());
        Mail::assertNothingSent();
    }

    public function test_reset_changes_the_password_for_every_firm(): void
    {
        $this->recover('firm-a', 'jane@example.com');
        $token = $this->sentToken();

        $this->reset('firm-a', 'jane@example.com', $token)->assertOk();

        $this->login('firm-b', 'jane@example.com', 'Brand-new-pass-1')->assertOk();
        $this->login('firm-a', 'jane@example.com', 'Brand-new-pass-1')->assertOk();
        $this->login('firm-a', 'jane@example.com', $this->password)->assertStatus(401);

        // The redeeming firm's standalone hash copy is refreshed too.
        $hash = app(TenantManager::class)->runIn(Tenant::where('slug', 'firm-a')->first(),
            fn () => User::where('email', 'jane@example.com')->value('password'), allowInactive: true);
        $this->assertTrue(Hash::check('Brand-new-pass-1', $hash));
    }

    public function test_token_is_single_use(): void
    {
        $this->recover('firm-a', 'jane@example.com');
        $token = $this->sentToken();

        $this->reset('firm-a', 'jane@example.com', $token)->assertOk();
        $this->reset('firm-a', 'jane@example.com', $token, 'Another-pass-22')->assertStatus(400);
    }

    public function test_wrong_token_and_wrong_email_are_rejected(): void
    {
        $this->recover('firm-a', 'jane@example.com');
        $token = $this->sentToken();

        $this->reset('firm-a', 'jane@example.com', 'not-the-token')->assertStatus(400);
        $this->reset('firm-a', 'carl@example.com', $token)->assertStatus(400);
        $this->login('firm-a', 'jane@example.com', $this->password)->assertOk(); // unchanged
    }

    public function test_token_expires_after_sixty_minutes(): void
    {
        $this->recover('firm-a', 'jane@example.com');
        $token = $this->sentToken();

        DB::connection('control')->table('identity_password_resets')->update(['created_at' => now()->subMinutes(61)]);

        $this->reset('firm-a', 'jane@example.com', $token)->assertStatus(400);
        $this->login('firm-a', 'jane@example.com', $this->password)->assertOk();
    }

    public function test_token_cannot_be_redeemed_at_a_firm_the_person_does_not_belong_to(): void
    {
        $this->recover('firm-a', 'jane@example.com');
        $token = $this->sentToken();

        $this->reset('firm-c', 'jane@example.com', $token)->assertStatus(400);
        $this->login('firm-a', 'jane@example.com', $this->password)->assertOk();
    }

    public function test_recovery_is_rate_limited_per_email_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('http://firm-a.app.test/api/v1/password-recovery', ['email' => 'jane@example.com'])->assertOk();
        }
        Mail::assertSent(ForgetPassword::class, 5);

        $this->postJson('http://firm-a.app.test/api/v1/password-recovery', ['email' => 'jane@example.com'])->assertOk();
        Mail::assertSent(ForgetPassword::class, 5); // sixth request sends nothing
    }

    public function test_short_password_is_rejected(): void
    {
        $this->recover('firm-a', 'jane@example.com');
        $token = $this->sentToken();

        $this->reset('firm-a', 'jane@example.com', $token, 'short')->assertStatus(422);
    }
}
