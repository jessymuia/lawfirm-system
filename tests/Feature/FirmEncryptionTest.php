<?php

namespace Tests\Feature;

use App\Secrets\IntegrationCredentials;
use App\Secrets\SecretInventory;
use App\Tenancy\Keys\BackupCipher;
use App\Tenancy\Keys\FirmCrypt;
use App\Tenancy\Keys\FirmKeys;
use App\Tenancy\Keys\KeyUnavailable;
use App\Tenancy\Models\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantLifecycle;
use App\Tenancy\TenantManager;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Kms\KmsClient;
use Aws\Result;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\OwnsTenancy;
use Tests\TestCase;

/** TEN-13: each firm's data is encrypted with its own key, which can be the firm's own KMS key and is destroyed with the firm. */
class FirmEncryptionTest extends TestCase implements OwnsTenancy
{
    use RefreshDatabase;

    private const ARN = 'arn:aws:kms:eu-west-1:123456789012:key/1234abcd-12ab-34cd-56ef-1234567890ab';

    private string $dir;

    private string $originalDefault;

    private string $master;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalDefault = config('database.default');
        $this->dir = sys_get_temp_dir().'/firmkeys-'.uniqid();
        mkdir($this->dir);
        touch("{$this->dir}/control.sqlite");
        $this->master = 'base64:'.base64_encode(random_bytes(32));
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$this->dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite',
            'tenancy.storage_disks' => ['local'],
            'tenancy.encryption.master_key' => $this->master,
            'tenancy.encryption.previous_master_keys' => [],
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        Storage::fake('local');
        config(['tenancy.mode' => 'multi']);
        app(FirmKeys::class)->forget();
    }

    protected function tearDown(): void
    {
        config(['tenancy.mode' => 'single', 'tenancy.encryption.master_key' => null]);
        app(FirmKeys::class)->forget();
        DB::setDefaultConnection($this->originalDefault);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function firm(string $slug): Tenant
    {
        return app(TenantLifecycle::class)->provision(['slug' => $slug, 'name' => ucfirst($slug), 'admin_email' => "admin@{$slug}.test",
            'domain' => "{$slug}.app.test", 'database' => "{$this->dir}/{$slug}.sqlite"])['tenant'];
    }

    private function in(Tenant $t, \Closure $fn): mixed
    {
        return app(TenantManager::class)->runIn($t, $fn, allowInactive: true);
    }

    private function storeSecret(Tenant $t, string $value): string
    {
        return $this->in($t, function () use ($value) {
            app(IntegrationCredentials::class)->set('stripe', ['secret_key' => $value], null);

            return (string) DB::table('integration_credentials')->value('value');
        });
    }

    private function readSecret(Tenant $t): ?string
    {
        return $this->in($t, fn () => app(IntegrationCredentials::class)->resolve('stripe', 'secret_key'));
    }

    /** A KMS that encrypts by tagging the key with the ARN and context, like the real one binds them. */
    private function fakeKms(bool $revoked = false): void
    {
        $handler = function (Command $cmd) use ($revoked) {
            $ctx = json_encode($cmd['EncryptionContext']);
            if ($revoked) {
                return new AwsException('denied', $cmd, ['code' => 'AccessDeniedException']);
            }
            if ($cmd->getName() === 'Encrypt') {
                return new Result(['CiphertextBlob' => "{$cmd['KeyId']}|{$ctx}|".base64_encode($cmd['Plaintext'])]);
            }
            [$arn, $boundTo, $key] = explode('|', $cmd['CiphertextBlob']);
            if ($arn !== $cmd['KeyId'] || $boundTo !== $ctx) {
                return new AwsException('wrong key or context', $cmd, ['code' => 'InvalidCiphertextException']);
            }

            return new Result(['Plaintext' => base64_decode($key)]);
        };
        $this->app->instance(KmsClient::class, new KmsClient(['version' => 'latest', 'region' => 'eu-west-1',
            'credentials' => ['key' => 'k', 'secret' => 's'], 'handler' => fn (Command $cmd) => Create::promiseFor($handler($cmd))
                ->then(fn ($r) => $r instanceof AwsException ? Create::rejectionFor($r) : $r)]));
    }

    public function test_every_new_firm_gets_its_own_data_key_and_its_secrets_are_sealed_with_it(): void
    {
        $a = $this->firm('acme');
        $b = $this->firm('bolt');
        $row = DB::connection('control')->table('tenant_keys')->where('tenant_id', $a->id)->first();
        $this->assertSame('platform', $row->provider);
        $this->assertStringStartsWith('v1:', $row->wrapped_key, 'stored wrapped, never in the clear');
        $this->assertSame(16, strlen($row->fingerprint));

        $sealed = $this->storeSecret($a, 'sk_live_acme');
        $this->assertSame('sk_live_acme', $this->readSecret($a));
        try {
            Crypt::decryptString($sealed);
            $this->fail('the app key must not open a firm\'s secret');
        } catch (DecryptException) {
        }
        $other = $this->in($b, fn () => FirmCrypt::encrypter());
        $this->expectException(DecryptException::class);
        $other->decryptString($sealed);
    }

    public function test_a_wrapped_key_only_opens_for_its_own_firm(): void
    {
        $a = $this->firm('acme');
        $b = $this->firm('bolt');
        $wrappedA = DB::connection('control')->table('tenant_keys')->where('tenant_id', $a->id)->value('wrapped_key');
        DB::connection('control')->table('tenant_keys')->where('tenant_id', $b->id)->update(['wrapped_key' => $wrappedA]);
        app(FirmKeys::class)->forget();

        $this->expectException(KeyUnavailable::class);
        app(FirmKeys::class)->encrypter($b);
    }

    public function test_an_existing_firm_is_given_a_key_and_its_secrets_move_onto_it(): void
    {
        config(['tenancy.encryption.master_key' => null]);
        $a = $this->firm('acme');
        $old = $this->storeSecret($a, 'sk_live_acme');
        $this->assertSame('sk_live_acme', Crypt::decryptString($old), 'no key yet: the app key, as before');
        $this->assertSame(1, Artisan::call('tenants:keys', ['slug' => 'acme', '--enable' => true]), 'needs the master key first');

        config(['tenancy.encryption.master_key' => $this->master]);
        $this->assertSame(0, Artisan::call('tenants:keys', ['slug' => 'acme', '--enable' => true]));
        $this->assertStringContainsString('data key created, 1 stored secret(s) moved onto it', Artisan::output());

        $new = (string) $this->in($a, fn () => DB::table('integration_credentials')->value('value'));
        $this->assertNotSame($old, $new);
        $this->assertSame('sk_live_acme', $this->readSecret($a));
        $this->assertSame('sk_live_acme', $this->in($a, fn () => FirmCrypt::encrypter())->decryptString($new));
        $this->assertSame(['current' => 1, 'previous' => 0, 'broken' => 0],
            array_intersect_key($this->in($a, fn () => app(SecretInventory::class)->check('tenant')), array_flip(['current', 'previous', 'broken'])));

        Artisan::call('tenants:keys', ['slug' => 'acme']);
        $this->assertMatchesRegularExpression('/^acme: active, platform, fingerprint [0-9a-f]{16}, usable/', Artisan::output());
    }

    public function test_bring_your_own_key_wraps_the_data_key_with_the_firms_kms_key(): void
    {
        $this->fakeKms();
        $a = $this->firm('acme');
        $this->storeSecret($a, 'sk_live_acme');
        $fingerprint = DB::connection('control')->table('tenant_keys')->value('fingerprint');

        $this->assertSame(1, Artisan::call('tenants:keys', ['slug' => 'acme', '--customer-key' => 'not-an-arn']));
        $this->assertSame(0, Artisan::call('tenants:keys', ['slug' => 'acme', '--customer-key' => self::ARN]));
        $row = DB::connection('control')->table('tenant_keys')->first();
        $this->assertSame(['aws-kms', self::ARN, $fingerprint], [$row->provider, $row->key_ref, $row->fingerprint], 'the same data key, now wrapped by the firm');
        $this->assertStringStartsWith(self::ARN.'|{"tenant":"acme"}|', base64_decode($row->wrapped_key));
        $this->assertSame('sk_live_acme', $this->readSecret($a), 'nothing had to be re-encrypted');

        // the firm's files on S3 are encrypted server-side with its key (direct uploads too)
        config(['filesystems.disks.s3docs' => ['driver' => 's3', 'bucket' => 'b', 'region' => 'eu-west-1', 'key' => 'k', 'secret' => 's'],
            'tenancy.storage_disks' => ['local', 's3docs']]);
        $this->in($a, fn () => $this->assertSame(['ServerSideEncryption' => 'aws:kms', 'SSEKMSKeyId' => self::ARN], config('filesystems.disks.s3docs.options')));
        $this->assertArrayNotHasKey('options', config('filesystems.disks.s3docs'), 'and not on the platform\'s own use of the disk');

        // the firm revokes the platform's use of its key: its data cannot be read, and nothing falls back to another key
        $this->fakeKms(revoked: true);
        app(FirmKeys::class)->forget();
        Artisan::call('tenants:keys', ['slug' => 'acme']);
        $this->assertStringContainsString('NOT USABLE', Artisan::output());
        $this->expectException(KeyUnavailable::class);
        $this->readSecret($a);
    }

    public function test_back_from_the_firms_key_to_the_platform_key(): void
    {
        $this->fakeKms();
        $a = $this->firm('acme');
        $this->storeSecret($a, 'sk_live_acme');
        app(FirmKeys::class)->useCustomerKey($a, self::ARN);
        $this->assertSame(0, Artisan::call('tenants:keys', ['slug' => 'acme', '--platform-key' => true]));
        $this->assertSame(['platform', null], [DB::connection('control')->table('tenant_keys')->value('provider'), DB::connection('control')->table('tenant_keys')->value('key_ref')]);
        $this->assertSame('sk_live_acme', $this->readSecret($a));
    }

    public function test_master_key_rotation_rewraps_without_touching_the_data(): void
    {
        $a = $this->firm('acme');
        $this->storeSecret($a, 'sk_live_acme');
        $before = DB::connection('control')->table('tenant_keys')->first();

        $new = 'base64:'.base64_encode(random_bytes(32));
        config(['tenancy.encryption.master_key' => $new, 'tenancy.encryption.previous_master_keys' => [$this->master]]);
        app(FirmKeys::class)->forget();
        $this->assertSame('sk_live_acme', $this->readSecret($a), 'the previous master key still opens it');

        $this->assertSame(0, Artisan::call('tenants:keys', ['--all' => true, '--rewrap' => true]));
        $this->assertStringContainsString('acme: rewrapped under the current master key.', Artisan::output());
        $after = DB::connection('control')->table('tenant_keys')->first();
        $this->assertNotSame($before->wrapped_key, $after->wrapped_key);
        $this->assertSame($before->fingerprint, $after->fingerprint);

        config(['tenancy.encryption.previous_master_keys' => []]);
        app(FirmKeys::class)->forget();
        $this->assertSame('sk_live_acme', $this->readSecret($a), 'the old master key can now be dropped');
        Artisan::call('tenants:keys', ['--all' => true, '--rewrap' => true]);
        $this->assertStringContainsString('already under the current key', Artisan::output());
    }

    public function test_backups_are_encrypted_with_the_firm_key_and_purging_the_firm_shreds_them(): void
    {
        $a = $this->firm('acme');
        file_put_contents("{$this->dir}/dump.sql.gz", gzencode('INSERT INTO clients VALUES (1, "Wanjiru")'));
        BackupCipher::encryptFile("{$this->dir}/dump.sql.gz", "{$this->dir}/dump.sql.gz.enc", app(FirmKeys::class)->encrypter($a)->getKey());
        $sealed = (string) file_get_contents("{$this->dir}/dump.sql.gz.enc");
        $this->assertStringStartsWith('LFBK1', $sealed);
        $this->assertStringNotContainsString((string) file_get_contents("{$this->dir}/dump.sql.gz"), $sealed);

        $this->assertSame(0, Artisan::call('tenants:decrypt-backup', ['slug' => 'acme', 'file' => "{$this->dir}/dump.sql.gz.enc", '--out' => "{$this->dir}/restored.sql.gz"]));
        $this->assertSame('INSERT INTO clients VALUES (1, "Wanjiru")', gzdecode((string) file_get_contents("{$this->dir}/restored.sql.gz")));

        app(TenantLifecycle::class)->offboard($a, 0);
        $report = app(TenantLifecycle::class)->purge($a->fresh(), withoutExport: 'test');
        $fingerprint = DB::connection('control')->table('tenant_keys')->value('fingerprint');
        $this->assertSame("destroyed: data key {$fingerprint}; its backups and encrypted secrets can no longer be read", $report['encryption_key']);
        $row = DB::connection('control')->table('tenant_keys')->first();
        $this->assertNull($row->wrapped_key);
        $this->assertNotNull($row->destroyed_at);

        // the backup copy still exists somewhere, and nobody can read it
        $this->assertSame(1, Artisan::call('tenants:decrypt-backup', ['slug' => 'acme', 'file' => "{$this->dir}/dump.sql.gz.enc", '--out' => "{$this->dir}/again.sql.gz"]));
        $this->assertStringContainsString('was destroyed', Artisan::output());
        $this->assertFileDoesNotExist("{$this->dir}/again.sql.gz");
    }

    public function test_purging_a_firm_on_its_own_key_says_that_key_is_the_firms_to_delete(): void
    {
        $this->fakeKms();
        $a = $this->firm('acme');
        app(FirmKeys::class)->useCustomerKey($a, self::ARN);
        app(TenantLifecycle::class)->offboard($a, 0);
        $report = app(TenantLifecycle::class)->purge($a->fresh(), withoutExport: 'test');
        $this->assertStringEndsWith("the firm's own KMS key ".self::ARN.' is theirs to disable or schedule for deletion', $report['encryption_key']);
        $this->assertNull(app(FirmKeys::class)->storageKmsKey('acme'), 'its files are no longer written under a destroyed key');
    }

    public function test_enabling_twice_keeps_the_same_key_and_only_a_firm_backup_is_decrypted(): void
    {
        $a = $this->firm('acme');
        $fingerprint = DB::connection('control')->table('tenant_keys')->value('fingerprint');
        $this->assertSame(0, Artisan::call('tenants:keys', ['slug' => 'acme', '--enable' => true]));
        $this->assertStringContainsString('already had a data key', Artisan::output());
        $this->assertSame([$fingerprint], DB::connection('control')->table('tenant_keys')->pluck('fingerprint')->all());

        file_put_contents("{$this->dir}/plain.sql.gz.enc", gzencode('not encrypted'));
        $this->assertSame(1, Artisan::call('tenants:decrypt-backup', ['slug' => 'acme', 'file' => "{$this->dir}/plain.sql.gz.enc"]));
        $this->assertStringContainsString('Not an encrypted firm backup.', Artisan::output());
        $this->assertFileDoesNotExist("{$this->dir}/plain.sql.gz");
    }

    public function test_a_firm_with_a_key_has_its_backups_written_encrypted(): void
    {
        $this->firm('acme');
        config(['tenancy.tenant_connection_template' => 'mysql']);   // a dry run never connects; the command needs a mysql firm
        Storage::fake(config('backup.disk'));
        $this->assertSame(0, Artisan::call('backup:run', ['--tenant' => 'acme', '--dry-run' => true]));
        $this->assertMatchesRegularExpression('#at tenants/acme/\d{4}/\d{2}/\d{2}/database-[0-9_-]+\.sql\.gz\.enc\.#', Artisan::output());
    }

    public function test_a_self_hosted_install_keeps_using_its_app_key(): void
    {
        config(['tenancy.mode' => 'single']);
        $this->assertFalse(app(FirmKeys::class)->enabled());
        $this->assertInstanceOf(Encrypter::class, FirmCrypt::encrypter());
        $this->assertSame(app('encrypter')->getKey(), FirmCrypt::encrypter()->getKey());
        $this->assertSame(1, Artisan::call('tenants:keys', ['--all' => true]));
        $this->assertStringContainsString('multi-tenant mode only', Artisan::output());
    }
}
