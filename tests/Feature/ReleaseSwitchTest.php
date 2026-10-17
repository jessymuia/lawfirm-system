<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PLAT-6: deploy/switch-release.sh against a real temporary directory layout (real bash, real symlinks, real tar).
 * The app itself is a stub; what is under test is the release mechanics: atomic flip, shared state, upgrade-before-flip,
 * health-check auto-rollback, rollback, retention.
 */
class ReleaseSwitchTest extends TestCase
{
    use RefreshDatabase;

    private string $base;

    private string $work;

    protected function setUp(): void
    {
        parent::setUp();
        if (! is_executable('/bin/bash') && ! trim((string) shell_exec('command -v bash'))) {
            $this->markTestSkipped('bash not available');
        }
        $this->work = sys_get_temp_dir().'/relsw-'.uniqid();
        $this->base = $this->work.'/app';
        mkdir($this->work, 0777, true);

        // A legacy git-checkout style install to convert from.
        mkdir($this->work.'/legacy/storage/app', 0777, true);
        file_put_contents($this->work.'/legacy/.env', "APP_KEY=base64:legacy\n");
        file_put_contents($this->work.'/legacy/storage/app/upload.txt', 'client file');
    }

    protected function tearDown(): void
    {
        if (isset($this->work) && str_starts_with($this->work, sys_get_temp_dir())) {
            (new Process(['rm', '-rf', $this->work]))->run();
        }
        parent::tearDown();
    }

    private function script(): string
    {
        return base_path('deploy/switch-release.sh');
    }

    private function sh(array $args, array $env = []): Process
    {
        $p = new Process(['bash', $this->script(), ...$args], null, array_merge(['DEPLOY_BASE' => $this->base, 'PHP' => 'true', 'UPGRADE_CMD' => 'true', 'QUEUE_CMD' => 'true'], $env));
        $p->run();

        return $p;
    }

    private function init(): void
    {
        $p = $this->sh(['init', $this->work.'/legacy']);
        $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
    }

    /** Build lawfirm-<version>.tar.gz (+ .sha256) containing a stub app. */
    private function bundle(string $version, bool $sum = true): string
    {
        $dir = "{$this->work}/src/lawfirm-{$version}";
        mkdir("$dir/public", 0777, true);
        mkdir("$dir/storage/logs", 0777, true); // skeleton that must be replaced by the shared storage
        file_put_contents("$dir/VERSION", $version);
        $out = "{$this->work}/lawfirm-{$version}.tar.gz";
        (new Process(['tar', '-C', "{$this->work}/src", '-czf', $out, "lawfirm-{$version}"]))->mustRun();
        if ($sum) {
            (new Process(['bash', '-c', 'sha256sum '.escapeshellarg(basename($out)).' > '.escapeshellarg(basename($out)).'.sha256'], $this->work))->mustRun();
        }

        return $out;
    }

    private function deploy(string $version, array $env = []): Process
    {
        usleep(5000);

        return $this->sh(['deploy', $this->bundle($version)], $env);
    }

    private function currentVersion(): string
    {
        clearstatcache(true); // PHP caches the resolved symlink path between reads
        return trim(file_get_contents($this->base.'/current/VERSION'));
    }

    private function releases(): array
    {
        return array_values(array_map('basename', glob($this->base.'/releases/*', GLOB_ONLYDIR)));
    }

    public function test_init_copies_state_and_leaves_the_legacy_install_untouched(): void
    {
        $this->init();

        $this->assertSame("APP_KEY=base64:legacy\n", file_get_contents($this->base.'/shared/.env'));
        $this->assertSame('client file', file_get_contents($this->base.'/shared/storage/app/upload.txt'));
        $this->assertFileExists($this->work.'/legacy/.env', 'legacy install is only read');
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->base.'/shared/.env')), -4));

        $again = $this->sh(['init', $this->work.'/legacy']);
        $this->assertNotSame(0, $again->getExitCode(), 'init must refuse to overwrite existing shared/.env');
    }

    public function test_deploy_creates_an_immutable_release_with_shared_state_linked(): void
    {
        $this->init();
        $p = $this->deploy('1.0.0');
        $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());

        $this->assertTrue(is_link($this->base.'/current'));
        $this->assertSame('1.0.0', $this->currentVersion());
        $rel = $this->base.'/current';
        $this->assertTrue(is_link($rel.'/.env'));
        $this->assertSame(realpath($this->base.'/shared/.env'), realpath($rel.'/.env'));
        $this->assertSame(realpath($this->base.'/shared/storage'), realpath($rel.'/storage'));
        $this->assertSame('client file', file_get_contents($rel.'/storage/app/upload.txt'), 'uploads survive into the new release');
    }

    public function test_second_deploy_flips_current_and_keeps_the_old_release_for_rollback(): void
    {
        $this->init();
        $this->deploy('1.0.0');
        $p = $this->deploy('1.1.0');
        $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());

        $this->assertSame('1.1.0', $this->currentVersion());
        $this->assertCount(2, $this->releases());
        $this->assertStringStartsWith('1.0.0-', trim(file_get_contents($this->base.'/.previous')));

        // state written through the new release lands in shared storage, visible to the old one too
        file_put_contents($this->base.'/current/storage/app/new.txt', 'written by 1.1.0');
        $this->assertFileExists($this->base.'/shared/storage/app/new.txt');
    }

    public function test_upgrade_runs_inside_the_new_release_before_the_flip(): void
    {
        $this->init();
        $this->deploy('1.0.0');
        // Records which release `current` pointed at while the upgrade ran, and where it ran.
        $probe = 'echo "cwd=$(basename "$PWD") current=$(basename "$(readlink '.$this->base.'/current)")" > '.$this->work.'/probe.txt';
        $this->deploy('1.1.0', ['UPGRADE_CMD' => $probe]);

        $probeOut = trim(file_get_contents($this->work.'/probe.txt'));
        $this->assertMatchesRegularExpression('/cwd=1\.1\.0-\d+ current=1\.0\.0-\d+/', $probeOut, 'upgrade ran in the NEW dir while the OLD release was still live');
    }

    public function test_failed_upgrade_discards_the_release_and_never_touches_current(): void
    {
        $this->init();
        $this->deploy('1.0.0');
        $before = readlink($this->base.'/current');

        $p = $this->deploy('1.1.0', ['UPGRADE_CMD' => 'exit 3']);

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('upgrade failed', $p->getErrorOutput());
        $this->assertSame($before, readlink($this->base.'/current'));
        $this->assertSame('1.0.0', $this->currentVersion());
        $this->assertCount(1, $this->releases(), 'the half-prepared release is removed');
    }

    public function test_unhealthy_release_is_rolled_back_automatically(): void
    {
        $this->init();
        $this->deploy('1.0.0');

        $p = $this->deploy('1.1.0', ['HEALTH_CMD' => 'grep -q 1.0.0 '.$this->base.'/current/VERSION']);

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('rolled back', $p->getErrorOutput());
        $this->assertSame('1.0.0', $this->currentVersion());
        $this->assertCount(2, $this->releases(), 'the bad release is kept for inspection');
    }

    public function test_healthy_release_passes_the_check_and_reload_hook_runs_on_every_flip(): void
    {
        $this->init();
        $log = $this->work.'/reload.log';
        $env = ['RELOAD_CMD' => 'echo reload >> '.$log, 'HEALTH_CMD' => 'test -f '.$this->base.'/current/VERSION'];
        $this->assertSame(0, $this->deploy('1.0.0', $env)->getExitCode());
        $this->assertSame(0, $this->deploy('1.1.0', $env)->getExitCode());
        $this->assertSame(0, $this->sh(['rollback'], $env)->getExitCode());

        $this->assertSame(3, substr_count(file_get_contents($log), 'reload'), 'two deploys and one rollback each reload FPM');
    }

    public function test_rollback_switches_back_and_forth_without_touching_data(): void
    {
        $this->init();
        $this->deploy('1.0.0');
        $this->deploy('1.1.0');

        $p = $this->sh(['rollback']);
        $this->assertSame(0, $p->getExitCode(), $p->getOutput().$p->getErrorOutput());
        $this->assertSame('1.0.0', $this->currentVersion());
        $this->assertFileExists($this->base.'/shared/storage/app/upload.txt');

        $this->sh(['rollback']);   // previous is now 1.1.0, so this rolls forward again
        $this->assertSame('1.1.0', $this->currentVersion());
    }

    public function test_rollback_to_a_named_release_and_guards(): void
    {
        $this->init();
        $this->deploy('1.0.0');
        $this->deploy('1.1.0');
        $this->deploy('1.2.0');
        $first = collect($this->releases())->first(fn ($r) => str_starts_with($r, '1.0.0-'));

        $this->assertSame(0, $this->sh(['rollback', $first])->getExitCode());
        $this->assertSame('1.0.0', $this->currentVersion());

        $this->assertNotSame(0, $this->sh(['rollback', $first])->getExitCode(), 'already current');
        $this->assertNotSame(0, $this->sh(['rollback', 'nope-1'])->getExitCode(), 'unknown release');
    }

    public function test_old_releases_are_pruned_but_current_and_previous_survive(): void
    {
        $this->init();
        foreach (['1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0'] as $v) {
            $this->assertSame(0, $this->deploy($v, ['KEEP' => '2'])->getExitCode());
        }

        $names = $this->releases();
        $this->assertCount(2, $names);
        $this->assertSame('1.4.0', $this->currentVersion());
        $this->assertTrue(collect($names)->contains(fn ($n) => str_starts_with($n, '1.3.0-')), 'previous is kept');
    }

    public function test_a_tampered_bundle_is_refused(): void
    {
        $this->init();
        $bundle = $this->bundle('1.0.0');
        file_put_contents($bundle, 'tampered', FILE_APPEND);

        $p = $this->sh(['deploy', $bundle]);

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('checksum', $p->getErrorOutput());
        $this->assertFalse(is_link($this->base.'/current'));
    }

    public function test_deploy_without_init_is_refused(): void
    {
        $p = $this->sh(['deploy', $this->bundle('1.0.0')]);
        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('init', $p->getErrorOutput());
    }

    public function test_the_flip_is_atomic_a_concurrent_reader_never_sees_a_missing_current(): void
    {
        $this->init();
        $this->deploy('1.0.0');
        $this->deploy('1.1.0');

        $reader = new Process(['bash', '-c', 'miss=0; for i in $(seq 1 3000); do [ -f '.escapeshellarg($this->base.'/current/VERSION').' ] || miss=$((miss+1)); done; echo "missing=$miss"']);
        $reader->start();
        for ($i = 0; $i < 15; $i++) {
            $this->sh(['rollback']);
        }
        $reader->wait();

        $this->assertSame('missing=0', trim($reader->getOutput()));
    }

    public function test_the_old_in_place_git_reset_flow_is_gone(): void
    {
        foreach (['deploy/deploy.sh', 'deploy/switch-release.sh', 'deploy/rollback.sh'] as $file) {
            $code = collect(explode("\n", file_get_contents(base_path($file))))->reject(fn ($l) => str_starts_with(ltrim($l), '#'))->implode("\n");
            $this->assertStringNotContainsString('git reset', $code, $file);
            $this->assertStringNotContainsString('git fetch', $code, $file);
        }
        $this->assertStringContainsString('switch-release.sh', file_get_contents(base_path('deploy/deploy.sh')));
        $this->assertStringNotContainsString('git reset --hard', preg_replace('/`[^`]*git reset --hard[^`]*`/', '', file_get_contents(base_path('docs/ops/DEPLOYMENT.md'))));
    }
}
