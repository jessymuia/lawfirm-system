<?php

namespace Tests\Feature;

use App\Models\User;
use App\Secrets\IntegrationCredentials;
use App\Security\Mfa;
use App\Security\Totp;
use App\Support\SecretFiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\CreatesTestUsers;
use Tests\TestCase;

/** SEC-15: secrets from mounted files, per-firm credentials encrypted at rest, and a rehearsable APP_KEY rotation. */
class SecretsTest extends TestCase
{
    use CreatesTestUsers, RefreshDatabase;

    private function newKey(): string
    {
        return 'base64:'.base64_encode(random_bytes(32));
    }

    /** Make the running app use $key (and $previous) for encryption, as a restart with new env would. */
    private function useKeys(string $key, array $previous = []): void
    {
        config(['app.key' => $key, 'app.previous_keys' => $previous]);
        $this->app->forgetInstance('encrypter');
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('encrypter');
    }

    private function tmp(string $content): string
    {
        $f = tempnam(sys_get_temp_dir(), 'sec');
        file_put_contents($f, $content);

        return $f;
    }

    // ---- *_FILE secrets --------------------------------------------------------------------------------------------

    public function test_a_file_fills_the_variable_and_one_trailing_newline_is_stripped(): void
    {
        $f = $this->tmp("s3cr3t value \n");
        $this->assertSame(['DB_PASSWORD' => 's3cr3t value '], SecretFiles::resolve(['DB_PASSWORD_FILE' => $f, 'OTHER' => 'x']));
        $f2 = $this->tmp("a\n\n");
        $this->assertSame(['DB_PASSWORD' => "a\n"], SecretFiles::resolve(['DB_PASSWORD_FILE' => $f2]));   // only one newline goes
        $f3 = $this->tmp("a\r\n");
        $this->assertSame(['DB_PASSWORD' => 'a'], SecretFiles::resolve(['DB_PASSWORD_FILE' => $f3]));
    }

    public function test_bad_secret_files_stop_the_boot_instead_of_running_without_the_secret(): void
    {
        $cases = [
            ['DB_PASSWORD_FILE' => '/no/such/file'],
            ['DB_PASSWORD_FILE' => 'relative/path'],
            ['DB_PASSWORD_FILE' => $this->tmp('')],
            ['DB_PASSWORD_FILE' => $this->tmp("\n")],
            ['DB_PASSWORD' => 'already', 'DB_PASSWORD_FILE' => $this->tmp('v')],      // two sources of truth
        ];
        foreach ($cases as $env) {
            $message = null;
            try {
                SecretFiles::resolve($env);
            } catch (\RuntimeException $e) {   // (not fail() inside the try: PHPUnit's own exceptions are RuntimeExceptions)
                $message = $e->getMessage();
            }
            $this->assertNotNull($message, 'expected a failure for '.json_encode($env));
            $this->assertStringContainsString('DB_PASSWORD', $message);
        }
        // a relative path is refused even when the file happens to exist in the working directory
        $dir = sys_get_temp_dir().'/sec-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/rel.txt', 'v');
        $cwd = getcwd();
        chdir($dir);
        try {
            SecretFiles::resolve(['DB_PASSWORD_FILE' => 'rel.txt']);
            $relOk = true;
        } catch (\RuntimeException) {
            $relOk = false;
        } finally {
            chdir($cwd);
        }
        $this->assertFalse($relOk);
        $this->assertSame([], SecretFiles::resolve(['_FILE' => '/x', 'PLAIN' => '1', 'DB_PASSWORD_FILE' => '']));
        // unrelated *_FILE variables are never read, even when they point nowhere
        $this->assertSame([], SecretFiles::resolve(['PIP_CONFIG_FILE' => '/does/not/exist', 'LICENSE_CLOCK_FILE' => '/x']));
        // ...unless the deployment opts a name in
        $f = $this->tmp("v\n");
        $this->assertSame(['MY_API_KEY' => 'v'], SecretFiles::resolve(['MY_API_KEY_FILE' => $f, 'SECRET_FILE_ALLOW' => 'MY_API_KEY']));
    }

    public function test_the_loader_publishes_into_the_process_environment(): void
    {
        $f = $this->tmp("loaded-from-file\n");
        putenv('SEC15_PROBE_FILE='.$f);
        putenv('SECRET_FILE_ALLOW=SEC15_PROBE');
        SecretFiles::load();
        $this->assertSame('loaded-from-file', getenv('SEC15_PROBE'));
        $this->assertSame('loaded-from-file', $_ENV['SEC15_PROBE']);
        putenv('SEC15_PROBE_FILE');
        putenv('SEC15_PROBE');
        putenv('SECRET_FILE_ALLOW');
    }

    // ---- per-firm credentials --------------------------------------------------------------------------------------

    public function test_a_firms_own_credentials_are_encrypted_write_only_and_win_over_the_deployment(): void
    {
        config(['services.textsms.api_key' => 'deployment-key', 'services.textsms.partner_id' => '111', 'services.textsms.shortcode' => 'DEP']);
        $admin = $this->userWithPermissions(['manage-security-settings']);
        $this->assertSame('deployment-key', textsmsPayload('0712345678', 'hi')['apikey']);

        $res = $this->actingAs($admin)->putJson('/api/v1/security/integrations/textsms', ['api_key' => 'firm-secret-key', 'partner_id' => '999']);
        $res->assertOk()->assertJsonPath('data.fields.api_key.source', 'firm')->assertJsonPath('data.fields.shortcode.source', 'deployment');
        $this->assertStringNotContainsString('firm-secret-key', $res->getContent());
        $this->assertStringNotContainsString('firm-secret-key', $this->actingAs($admin)->getJson('/api/v1/security/integrations/textsms')->getContent());

        $stored = DB::table('integration_credentials')->where('name', 'api_key')->value('value');
        $this->assertStringNotContainsString('firm-secret-key', $stored);
        $this->assertSame('firm-secret-key', Crypt::decryptString($stored));
        $p = textsmsPayload('0712345678', 'hi');
        $this->assertSame(['firm-secret-key', '999', 'DEP', '254712345678'], [$p['apikey'], $p['partnerID'], $p['shortcode'], $p['mobile']]);

        // never in the audit trail, which names fields only
        $audits = DB::table('audits')->where('event', 'integration_credentials_changed')->get();
        $this->assertCount(1, $audits);
        $this->assertStringNotContainsString('firm-secret-key', json_encode($audits));
        $this->assertStringContainsString('api_key', $audits[0]->new_values);

        // clearing a field returns it to the deployment value
        $this->actingAs($admin)->putJson('/api/v1/security/integrations/textsms', ['api_key' => ''])->assertOk();
        $this->assertSame('deployment-key', textsmsPayload('0712345678', 'hi')['apikey']);
    }

    public function test_credentials_need_the_permission_and_a_known_provider_and_field(): void
    {
        $this->actingAs($this->userWithPermissions(['list-clients']))->putJson('/api/v1/security/integrations/textsms', ['api_key' => 'x'])->assertForbidden();
        $admin = $this->userWithPermissions(['manage-security-settings']);
        $this->actingAs($admin)->putJson('/api/v1/security/integrations/paypal', ['api_key' => 'x'])->assertNotFound();
        $this->actingAs($admin)->getJson('/api/v1/security/integrations/paypal')->assertNotFound();
        $this->expectException(\InvalidArgumentException::class);
        app(IntegrationCredentials::class)->set('textsms', ['nonsense' => 'x'], null);
    }

    // ---- key rotation rehearsal ------------------------------------------------------------------------------------

    private function seedSecrets(): array
    {
        $user = User::factory()->create();
        $secret = Totp::generateSecret();
        DB::table('user_mfa')->insert(['user_id' => $user->id, 'secret' => Crypt::encryptString($secret), 'confirmed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        app(IntegrationCredentials::class)->set('textsms', ['api_key' => 'rotating-key'], null);
        DB::table('sso_providers')->insert(['issuer' => 'https://idp.example.test', 'client_id' => 'c', 'client_secret' => Crypt::encryptString('idp-secret'), 'enabled' => false, 'enforce' => false, 'idp_enforces_mfa' => false, 'allowed_email_domains' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        return [$user, $secret];
    }

    public function test_app_key_rotation_end_to_end(): void
    {
        $keyA = config('app.key');
        [$user, $secret] = $this->seedSecrets();
        $this->artisan('secrets:check --strict')->assertSuccessful();

        // 1. the new key is deployed with the old one as a previous key: everything still works, nothing is rewritten yet
        $keyB = $this->newKey();
        $this->useKeys($keyB, [$keyA]);
        $this->assertSame('rotating-key', app(IntegrationCredentials::class)->resolve('textsms', 'api_key'));
        $this->assertTrue(Mfa::enabled($user->fresh()));
        $this->artisan('secrets:check')->expectsOutputToContain('needs-previous-key=3')->assertSuccessful();
        $this->artisan('secrets:check --strict')->assertFailed();           // not yet safe to drop the old key

        // 2. a dry run changes nothing
        $before = DB::table('user_mfa')->value('secret');
        $this->artisan('secrets:rotate')->expectsOutputToContain('would-rotate=3')->assertSuccessful();
        $this->assertSame($before, DB::table('user_mfa')->value('secret'));

        // 3. rotate
        $this->artisan('secrets:rotate --execute')->expectsOutputToContain('rotated=3')->assertSuccessful();
        $this->assertNotSame($before, DB::table('user_mfa')->value('secret'));
        $this->artisan('secrets:check --strict')->assertSuccessful();
        $this->artisan('secrets:rotate --execute')->expectsOutputToContain('rotated=0')->assertSuccessful();   // idempotent

        // 4. the old key is dropped: nothing breaks, and the old key alone can no longer read anything
        $this->useKeys($keyB, []);
        $this->assertSame('rotating-key', app(IntegrationCredentials::class)->resolve('textsms', 'api_key'));
        $this->assertSame('idp-secret', Crypt::decryptString(DB::table('sso_providers')->value('client_secret')));
        $this->assertSame($secret, Crypt::decryptString(DB::table('user_mfa')->value('secret')));
        $this->artisan('secrets:check --strict')->assertSuccessful();
        $this->useKeys($keyA, []);
        $this->artisan('secrets:check')->expectsOutputToContain('unreadable=3')->assertFailed();
    }

    public function test_unreadable_values_are_reported_and_never_touched(): void
    {
        $this->seedSecrets();
        $this->useKeys($this->newKey(), []);    // rotated the key but forgot the previous one
        $before = DB::table('user_mfa')->value('secret');
        $this->artisan('secrets:rotate --execute')->expectsOutputToContain('unreadable=3')->assertFailed();
        $this->assertSame($before, DB::table('user_mfa')->value('secret'));
    }

    public function test_rotation_never_overwrites_a_value_that_changed_after_it_was_read(): void
    {
        $this->seedSecrets();
        $this->useKeys($this->newKey(), [config('app.key')]);
        $fresh = Crypt::encryptString('written-by-someone-else');
        $fired = false;
        // right after the rotation pass reads user_mfa, another writer replaces the value
        DB::listen(function ($q) use (&$fired, $fresh) {
            if (! $fired && str_starts_with(strtolower($q->sql), 'select') && str_contains($q->sql, 'user_mfa') && str_contains($q->sql, 'secret')) {   // the row read, not the table-exists probe
                $fired = true;
                DB::table('user_mfa')->update(['secret' => $fresh]);
            }
        });
        app(\App\Secrets\SecretInventory::class)->rotate('tenant', true);
        $this->assertTrue($fired);
        $this->assertSame($fresh, DB::table('user_mfa')->value('secret'));    // the stale pass did not clobber it
    }

    // ---- guard against unregistered encrypted data -----------------------------------------------------------------

    public function test_every_place_that_encrypts_is_registered_for_rotation(): void
    {
        $allowed = config('secrets.encrypting_sources');
        $found = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() === 'php' && preg_match('/Crypt::encrypt(String)?\(|[^a-zA-Z>:_]encrypt(String)?\(|\'encrypted(:array|:object|:collection)?\'|\'encrypted\'/', file_get_contents($f->getPathname()))) {
                $found[] = str_replace(base_path().'/', '', $f->getPathname());
            }
        }
        sort($found);
        $this->assertSame(collect($allowed)->sort()->values()->all(), $found, 'Code that encrypts data must be listed in config/secrets.php (encrypting_sources and encrypted) so key rotation can re-encrypt it.');

        foreach (config('secrets.encrypted') as $t) {
            if ($t['scope'] === 'tenant') {
                $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($t['table'], $t['column']), "{$t['table']}.{$t['column']} does not exist");
            }
        }
    }
}
