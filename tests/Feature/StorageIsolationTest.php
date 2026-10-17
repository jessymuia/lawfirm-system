<?php

namespace Tests\Feature;

use App\Console\Commands\StorageIamPolicy;
use App\Models\Cases;
use App\Tenancy\TenantContext;
use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\Sts\StsClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** TEN-12: firm files live under tenants/<firm>/, older files are moved there verified, and storage credentials are confined to it. */
class StorageIsolationTest extends TestCase implements \Tests\OwnsTenancy
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        config(['tenancy.storage_role_arn' => null]);
        app(TenantContext::class)->activate('default');
        parent::tearDown();
    }

    private function doc(string $path, ?string $bytes, ?string $checksum = 'auto'): int
    {
        if ($bytes !== null) {
            Storage::disk('local')->put($path, $bytes);
        }
        $case = Cases::factory()->create();

        return DB::table('documents')->insertGetId([
            'case_id' => $case->id, 'version' => 1, 'is_current' => 1, 'title' => basename($path), 'filename' => basename($path),
            'filepath' => $path, 'full_path' => $path, 'disk' => 'local', 'mimetype' => 'application/pdf', 'filesize' => strlen((string) $bytes),
            'checksum' => $checksum === 'auto' ? hash('sha256', (string) $bytes) : $checksum, 'extension' => 'pdf',
            'created_at' => '2020-01-01 00:00:00', 'updated_at' => '2020-01-01 00:00:00',
        ]);
    }

    // ---- moving older documents ----------------------------------------------------------------------------------

    public function test_older_documents_move_under_the_firm_prefix_verified_by_checksum(): void
    {
        Storage::fake('local');
        $a = $this->doc('cases/docs/a.pdf', 'alpha');
        $b = $this->doc('cases/docs/b.pdf', 'bravo', checksum: null);          // predates checksum-on-write
        $c = $this->doc('tenants/default/cases/docs/c.pdf', 'charlie');      // already in place
        $missing = $this->doc('cases/docs/gone.pdf', null);
        $damaged = $this->doc('cases/docs/d.pdf', 'delta', checksum: hash('sha256', 'something else'));
        $audits = DB::table('audits')->count();

        $this->artisan('documents:relocate')->expectsOutputToContain('2 moved, 0 already in place, 1 missing, 1 failed.')->assertExitCode(1);

        $row = fn ($id) => DB::table('documents')->find($id);
        $this->assertSame(['tenants/default/cases/docs/a.pdf', 'tenants/default/cases/docs/a.pdf'], [$row($a)->full_path, $row($a)->filepath]);
        $this->assertSame('alpha', Storage::disk('local')->get('tenants/default/cases/docs/a.pdf'));
        Storage::disk('local')->assertMissing('cases/docs/a.pdf');
        $this->assertSame(hash('sha256', 'bravo'), $row($b)->checksum, 'the checksum is recorded once verified');
        $this->assertSame('tenants/default/cases/docs/c.pdf', $row($c)->full_path);
        $this->assertSame('cases/docs/gone.pdf', $row($missing)->full_path);
        $this->assertSame('cases/docs/d.pdf', $row($damaged)->full_path, 'a file that fails its checksum is not moved');
        Storage::disk('local')->assertExists('cases/docs/d.pdf');
        Storage::disk('local')->assertMissing('tenants/default/cases/docs/d.pdf');
        $this->assertSame('2020-01-01 00:00:00', (string) $row($a)->updated_at);
        $this->assertSame($audits, DB::table('audits')->count());
    }

    public function test_in_multi_mode_each_firm_s_documents_move_under_its_own_prefix(): void
    {
        Storage::fake('local');
        $original = config('database.default');
        $dir = sys_get_temp_dir().'/ten12-'.uniqid();
        mkdir($dir);
        touch("{$dir}/control.sqlite");
        config([
            'database.connections.control' => ['driver' => 'sqlite', 'database' => "{$dir}/control.sqlite", 'prefix' => '', 'foreign_key_constraints' => true],
            'tenancy.tenant_connection_template' => 'sqlite', 'tenancy.storage_disks' => ['local'],
        ]);
        Artisan::call('migrate', ['--database' => 'control', '--path' => 'database/migrations/control', '--force' => true]);
        $manager = app(\App\Tenancy\TenantManager::class);
        $firms = [];
        $run = substr(uniqid(), -6);
        foreach (["alpha{$run}", "beta{$run}"] as $slug) {
            $firms[$slug] = app(\App\Tenancy\TenantLifecycle::class)->provision(['slug' => $slug, 'name' => $slug, 'admin_email' => "a@{$slug}.test"])['tenant'];
        }
        config(['tenancy.mode' => 'multi']);

        try {
            foreach ($firms as $slug => $t) {
                $manager->runIn($t, fn () => $this->doc("cases/docs/{$slug}.pdf", "bytes of {$slug}"));
            }

            $this->artisan('documents:relocate')->expectsOutputToContain('2 moved')->assertExitCode(0);

            foreach ($firms as $slug => $t) {
                $this->assertSame("tenants/{$slug}/cases/docs/{$slug}.pdf", $manager->runIn($t, fn () => DB::table('documents')->value('full_path')));
                $this->assertSame("bytes of {$slug}", Storage::disk('local')->get("tenants/{$slug}/cases/docs/{$slug}.pdf"));
            }
        } finally {
            config(['tenancy.mode' => 'single']);
            DB::setDefaultConnection($original);
            foreach ($firms as $t) {
                @unlink($t->database);
            }
        }
    }

    public function test_a_dry_run_changes_nothing_and_a_rerun_resumes_an_interrupted_move(): void
    {
        Storage::fake('local');
        $id = $this->doc('cases/docs/a.pdf', 'alpha');

        $this->artisan('documents:relocate --dry-run')->expectsOutputToContain('1 to move')->assertExitCode(0);
        $this->assertSame('cases/docs/a.pdf', DB::table('documents')->where('id', $id)->value('full_path'));
        Storage::disk('local')->assertMissing('tenants/default/cases/docs/a.pdf');

        // a run that died after copying and deleting the source, before switching the row
        Storage::disk('local')->move('cases/docs/a.pdf', 'tenants/default/cases/docs/a.pdf');
        $this->artisan('documents:relocate')->expectsOutputToContain('0 moved, 1 already in place')->assertExitCode(0);
        $this->assertSame('tenants/default/cases/docs/a.pdf', DB::table('documents')->where('id', $id)->value('full_path'));

        $this->artisan('documents:relocate')->expectsOutputToContain('0 moved, 0 already in place, 0 missing, 0 failed.')->assertExitCode(0);
    }

    public function test_it_never_overwrites_a_different_file_at_the_target(): void
    {
        Storage::fake('local');
        $id = $this->doc('cases/docs/a.pdf', 'alpha');
        Storage::disk('local')->put('tenants/default/cases/docs/a.pdf', 'not alpha');

        $this->artisan('documents:relocate')->expectsOutputToContain('a different file already exists')->assertExitCode(1);

        $this->assertSame('cases/docs/a.pdf', DB::table('documents')->where('id', $id)->value('full_path'));
        $this->assertSame('not alpha', Storage::disk('local')->get('tenants/default/cases/docs/a.pdf'));
        Storage::disk('local')->assertExists('cases/docs/a.pdf');
    }

    public function test_keep_old_leaves_the_source_after_a_verified_move(): void
    {
        Storage::fake('local');
        $this->doc('cases/docs/a.pdf', 'alpha');

        $this->artisan('documents:relocate --keep-old')->assertExitCode(0);

        Storage::disk('local')->assertExists('cases/docs/a.pdf');
        Storage::disk('local')->assertExists('tenants/default/cases/docs/a.pdf');
    }

    // ---- storage credentials confined to the firm ------------------------------------------------------------------

    public function test_a_firm_uses_role_credentials_tagged_with_itself_and_the_platform_keeps_its_own(): void
    {
        $calls = [];
        $mock = new MockHandler;
        $answer = function (CommandInterface $cmd) use (&$calls) {
            $calls[] = $cmd->toArray();
            $tag = $cmd['Tags'][0]['Value'];

            return new Result(['Credentials' => ['AccessKeyId' => 'ASIA-'.$tag, 'SecretAccessKey' => 's', 'SessionToken' => 't', 'Expiration' => now()->addHour()->toIso8601String()]]);
        };
        $mock->append($answer, $answer);
        $this->app->instance(StsClient::class, new StsClient(['region' => 'eu-west-1', 'version' => 'latest', 'handler' => $mock, 'credentials' => ['key' => 'k', 'secret' => 's']]));
        config([
            'filesystems.disks.s3' => ['driver' => 's3', 'key' => 'PLATFORM', 'secret' => 'platform-secret', 'region' => 'eu-west-1', 'bucket' => 'firm-files'],
            'tenancy.storage_disks' => ['s3'],
            'tenancy.storage_role_arn' => 'arn:aws:iam::123:role/tenant-storage',
        ]);
        $key = fn () => Storage::disk('s3')->getClient()->getCredentials()->wait()->getAccessKeyId();
        $context = app(TenantContext::class);

        $context->activate('acme');
        $this->assertSame('ASIA-acme', $key());
        $this->assertSame('ASIA-acme', $key(), 'reused until it expires');
        $this->assertNull(config('filesystems.disks.s3.key'), "the platform's key is not on a firm's disk");

        $context->activate('default');
        $this->assertSame('PLATFORM', $key());

        $context->activate('globex');
        $this->assertSame('ASIA-globex', $key());

        $this->assertCount(2, $calls);
        $this->assertSame('arn:aws:iam::123:role/tenant-storage', $calls[0]['RoleArn']);
        $this->assertSame([['Key' => 'tenant', 'Value' => 'acme']], $calls[0]['Tags']);
        $this->assertSame('tenant-acme', $calls[0]['RoleSessionName']);
    }

    public function test_without_a_role_nothing_changes(): void
    {
        config(['filesystems.disks.s3' => ['driver' => 's3', 'key' => 'PLATFORM', 'secret' => 'x', 'region' => 'eu-west-1', 'bucket' => 'b'], 'tenancy.storage_disks' => ['s3']]);

        app(TenantContext::class)->activate('acme');

        $this->assertSame('PLATFORM', config('filesystems.disks.s3.key'));
    }

    // ---- the policy -----------------------------------------------------------------------------------------------

    /** Allowed by the tenant role for a session tagged $firm? (Allow statements, `*` wildcards, Deny on missing tag.) */
    private function allows(array $policy, ?string $firm, string $action, string $resource, ?string $prefix = null): bool
    {
        $allowed = false;
        foreach ($policy['Statement'] as $s) {
            $sub = fn ($v) => str_replace('${aws:PrincipalTag/tenant}', (string) $firm, $v);
            $actions = (array) $s['Action'];
            $actionHit = in_array($action, $actions, true) || in_array('s3:*', $actions, true);
            $resourceHit = collect((array) $s['Resource'])->contains(fn ($r) => fnmatch($sub($r), $resource));
            if (! $actionHit || ! $resourceHit) {
                continue;
            }
            $cond = $s['Condition'] ?? [];
            if (isset($cond['Null'])) {
                if ($s['Effect'] === 'Deny' && $firm === null) {
                    return false;
                }

                continue;
            }
            if (isset($cond['StringLike']['s3:prefix']) && ! collect($cond['StringLike']['s3:prefix'])->contains(fn ($p) => fnmatch($sub($p), (string) $prefix))) {
                continue;
            }
            $allowed = $allowed || $s['Effect'] === 'Allow';
        }

        return $allowed;
    }

    public function test_the_tenant_role_policy_confines_a_firm_to_its_own_prefix(): void
    {
        $p = StorageIamPolicy::documents('firm-files', 'arn:aws:iam::1:role/app', 'arn:aws:iam::1:role/tenant-storage')['tenant-role'];

        $this->assertTrue($this->allows($p, 'acme', 's3:GetObject', 'arn:aws:s3:::firm-files/tenants/acme/cases/docs/x.pdf'));
        $this->assertTrue($this->allows($p, 'acme', 's3:PutObject', 'arn:aws:s3:::firm-files/tenants/acme/exports/e.zip'));
        $this->assertFalse($this->allows($p, 'acme', 's3:GetObject', 'arn:aws:s3:::firm-files/tenants/globex/cases/docs/x.pdf'), 'another firm');
        $this->assertFalse($this->allows($p, 'acme', 's3:GetObject', 'arn:aws:s3:::firm-files/tenants/acme2/cases/docs/x.pdf'), 'a firm whose key starts the same');
        $this->assertFalse($this->allows($p, 'acme', 's3:GetObject', 'arn:aws:s3:::firm-files/cases/docs/x.pdf'), 'unprefixed (pre-TEN-12) keys');
        $this->assertFalse($this->allows($p, 'acme', 's3:GetObject', 'arn:aws:s3:::other-bucket/tenants/acme/x'));
        $this->assertTrue($this->allows($p, 'acme', 's3:ListBucket', 'arn:aws:s3:::firm-files', 'tenants/acme/cases/'));
        $this->assertFalse($this->allows($p, 'acme', 's3:ListBucket', 'arn:aws:s3:::firm-files', 'tenants/'));
        $this->assertFalse($this->allows($p, 'acme', 's3:ListBucket', 'arn:aws:s3:::firm-files', 'tenants/globex/'));
        $this->assertFalse($this->allows($p, null, 's3:GetObject', 'arn:aws:s3:::firm-files/tenants//x'), 'no tag, no access');
    }

    public function test_the_trust_policy_requires_exactly_one_tenant_tag_and_the_command_prints_it(): void
    {
        $trust = StorageIamPolicy::documents('b', 'arn:aws:iam::1:role/app', 'r')['trust']['Statement'][0];
        $this->assertSame(['sts:AssumeRole', 'sts:TagSession'], $trust['Action']);
        $this->assertSame(['tenant'], $trust['Condition']['ForAllValues:StringEquals']['aws:TagKeys']);
        $this->assertSame('arn:aws:iam::1:role/app', $trust['Principal']['AWS']);

        $this->assertSame(0, Artisan::call('storage:iam-policy', ['--bucket' => 'firm-files', '--part' => 'tenant-role']));
        $printed = json_decode(Artisan::output(), true);
        $this->assertSame('arn:aws:s3:::firm-files/tenants/${aws:PrincipalTag/tenant}/*', $printed['Statement'][0]['Resource']);
        $this->assertSame(1, Artisan::call('storage:iam-policy', ['--bucket' => 'b', '--part' => 'nope']));
    }

    // ---- imports never use a shared or foreign key ------------------------------------------------------------------

    public function test_imported_files_always_land_under_the_importing_firm(): void
    {
        app(TenantContext::class)->activate('beta');
        $importer = app(\App\Portability\Importer::class);
        $bundle = (new \ReflectionClass(\App\Portability\Bundle::class))->newInstanceWithoutConstructor();
        $target = fn (string $p) => (new \ReflectionMethod($importer, 'targetPath'))->invoke($importer, $bundle, $p);

        $this->assertSame('tenants/beta/cases/docs/a.pdf', $target('tenants/alpha/cases/docs/a.pdf'));
        $this->assertSame('tenants/beta/cases/docs/a.pdf', $target('cases/docs/a.pdf'));
        $this->assertSame('tenants/beta/cases/docs/a.pdf', $target('tenants/beta/cases/docs/a.pdf'));
    }
}
