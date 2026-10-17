<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** PLAT-5: first-run/upgrade installer is idempotent and never destructive. */
#[\PHPUnit\Framework\Attributes\Group('single-mode')]   // self-hosted installs only (TEN-17)
class AppInstallTest extends TestCase
{
    use RefreshDatabase;

    private function install(array $opts = [])
    {
        return $this->artisan('app:install', array_merge(['--no-interaction' => true], $opts));
    }

    public function test_first_install_creates_an_admin_with_every_permission(): void
    {
        $this->install(['--admin-email' => 'owner@firm.test', '--admin-password' => 'a-long-secret-pass', '--admin-name' => 'Owner'])->assertExitCode(0);

        $user = User::where('email', 'owner@firm.test')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue(Hash::check('a-long-secret-pass', $user->password));
        $this->assertSame(Permission::count(), Role::findByName('admin')->permissions->count());
        $this->assertTrue($user->checkPermissionTo('manage-license'));
        $this->assertTrue(Role::where('name', 'advocate')->exists());
    }

    public function test_running_it_twice_changes_nothing_and_does_not_fail(): void
    {
        $args = ['--admin-email' => 'owner@firm.test', '--admin-password' => 'a-long-secret-pass'];
        $this->install($args)->assertExitCode(0);
        $this->install($args)->assertExitCode(0);

        $this->assertSame(1, User::count());
        $this->assertSame(1, Role::where('name', 'admin')->count());
    }

    public function test_it_never_wipes_existing_data(): void
    {
        $existing = User::factory()->create(['email' => 'keep@firm.test']);
        $client = \App\Models\Client::factory()->create();
        $usersBefore = User::count();

        $this->install()->assertExitCode(0);   // no admin options needed once users exist

        $this->assertNotNull(User::find($existing->id));
        $this->assertNotNull(\App\Models\Client::find($client->id));
        $this->assertSame($usersBefore, User::count(), 'no second admin is invented');
    }

    public function test_a_permission_added_by_an_upgrade_reaches_the_admin_role(): void
    {
        $this->install(['--admin-email' => 'owner@firm.test', '--admin-password' => 'a-long-secret-pass']);
        Permission::findOrCreate('brand-new-permission-from-v2');

        $this->install()->assertExitCode(0);

        $this->assertTrue(Role::findByName('admin')->hasPermissionTo('brand-new-permission-from-v2'));
    }

    public function test_a_generated_password_is_shown_once_and_works(): void
    {
        $this->install(['--admin-email' => 'owner@firm.test'])
            ->expectsOutputToContain('Generated password')
            ->assertExitCode(0);

        $this->assertNotSame('', User::first()->password);
    }

    public function test_a_supplied_password_is_never_echoed(): void
    {
        $this->install(['--admin-email' => 'owner@firm.test', '--admin-password' => 'a-long-secret-pass'])
            ->doesntExpectOutputToContain('a-long-secret-pass')
            ->doesntExpectOutputToContain('Generated password')
            ->assertExitCode(0);
    }

    public function test_bad_input_fails_before_touching_the_database(): void
    {
        $this->install()->assertExitCode(1);                                             // no email, non-interactive
        $this->install(['--admin-email' => 'not-an-email'])->assertExitCode(1);
        $this->install(['--admin-email' => 'a@b.test', '--admin-password' => 'short'])->assertExitCode(1);

        $this->assertSame(0, User::count());
    }

    public function test_app_init_refuses_to_wipe_without_the_explicit_flag(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:init')->expectsOutputToContain('DROPS ALL DATA')->assertExitCode(1);

        $this->assertNotNull(User::find($user->id), 'data survived');
    }

    public function test_version_comes_from_the_version_file(): void
    {
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), config('app.version'));
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', config('app.version'), 'VERSION must be semver');
        $this->artisan('app:version')->expectsOutput(config('app.version'))->assertExitCode(0);
    }
}
