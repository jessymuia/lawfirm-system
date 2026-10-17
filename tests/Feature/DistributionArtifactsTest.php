<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/** PLAT-5: the things a customer receives (image recipe, compose stack, bundle, release tooling) are consistent and safe. */
class DistributionArtifactsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    private function run_(array $cmd, array $env = [], ?string $cwd = null): Process
    {
        $p = new Process($cmd, $cwd ?? $this->root, array_merge(['HOME' => sys_get_temp_dir()], $env), null, 300);
        $p->run();

        return $p;
    }

    private function tmp(): string
    {
        $d = sys_get_temp_dir().'/dist-'.uniqid();
        mkdir($d, 0777, true);

        return $d;
    }

    /** A throwaway tree containing just what release.sh edits. */
    private function releaseTree(string $version = '1.0.0', string $unreleased = "- Something new\n"): string
    {
        $d = $this->tmp();
        mkdir("$d/deploy/helm/lawfirm", 0777, true);
        mkdir("$d/deploy/selfhost", 0777, true);
        file_put_contents("$d/VERSION", "$version\n");
        file_put_contents("$d/CHANGELOG.md", "# Changelog\n\n## [Unreleased]\n$unreleased\n## [$version]\n- old\n");
        file_put_contents("$d/deploy/helm/lawfirm/Chart.yaml", "apiVersion: v2\nname: lawfirm\nversion: $version\nappVersion: \"$version\"\n");
        file_put_contents("$d/deploy/selfhost/.env.example", "LAWFIRM_VERSION=$version\nOTHER=keep\n");

        return $d;
    }

    private function release(string $tree, string $version): Process
    {
        return $this->run_(['bash', "{$this->root}/scripts/release.sh", $version], ['ROOT' => $tree, 'RELEASE_NO_GIT' => '1']);
    }

    // ---- versions stay in step -------------------------------------------------

    public function test_version_chart_env_and_changelog_agree(): void
    {
        $v = trim(file_get_contents("{$this->root}/VERSION"));
        $chart = Yaml::parseFile("{$this->root}/deploy/helm/lawfirm/Chart.yaml");

        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $v);
        $this->assertSame($v, (string) $chart['version']);
        $this->assertSame($v, (string) $chart['appVersion']);
        $this->assertStringContainsString("LAWFIRM_VERSION=$v", file_get_contents("{$this->root}/deploy/selfhost/.env.example"));
        $this->assertStringContainsString("## [$v]", file_get_contents("{$this->root}/CHANGELOG.md"));
    }

    // ---- release.sh --------------------------------------------------------------

    public function test_release_script_updates_every_version_reference(): void
    {
        $t = $this->releaseTree('1.0.0');

        $p = $this->release($t, '1.1.0');

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput().$p->getOutput());
        $this->assertSame("1.1.0\n", file_get_contents("$t/VERSION"));
        $chart = Yaml::parseFile("$t/deploy/helm/lawfirm/Chart.yaml");
        $this->assertSame('1.1.0', (string) $chart['version']);
        $this->assertSame('1.1.0', (string) $chart['appVersion']);
        $this->assertStringContainsString("LAWFIRM_VERSION=1.1.0\nOTHER=keep", file_get_contents("$t/deploy/selfhost/.env.example"));
        $log = file_get_contents("$t/CHANGELOG.md");
        $this->assertMatchesRegularExpression('/## \[Unreleased\]\n\n## \[1\.1\.0\] - \d{4}-\d{2}-\d{2}\n- Something new/', $log);
        $this->assertStringContainsString('## [1.0.0]', $log, 'older sections are kept');
    }

    /** @dataProvider badVersions */
    public function test_release_script_rejects_malformed_versions(string $bad): void
    {
        $t = $this->releaseTree();

        $this->assertNotSame(0, $this->release($t, $bad)->getExitCode(), $bad);
        $this->assertSame("1.0.0\n", file_get_contents("$t/VERSION"), 'nothing was changed');
    }

    public static function badVersions(): array
    {
        return array_combine(['v1.2.3', '1.2', '1.2.3.4', '01.2.3', '1.2.3-', 'latest', '1.2.3 rc', '1.2.3;rm'], array_map(fn ($v) => [$v], ['v1.2.3', '1.2', '1.2.3.4', '01.2.3', '1.2.3-', 'latest', '1.2.3 rc', '1.2.3;rm']));
    }

    public function test_release_script_refuses_to_go_backwards_or_repeat(): void
    {
        $t = $this->releaseTree('2.0.0');

        $this->assertNotSame(0, $this->release($t, '1.9.9')->getExitCode());
        $this->assertNotSame(0, $this->release($t, '2.0.0')->getExitCode());
        $this->assertSame("2.0.0\n", file_get_contents("$t/VERSION"));
    }

    public function test_release_script_accepts_prerelease_ordering(): void
    {
        $t = $this->releaseTree('1.0.0');
        $this->assertSame(0, $this->release($t, '1.1.0-rc.1')->getExitCode());
        $final = $this->release($t, '1.1.0');
        $this->assertSame(0, $final->getExitCode(), 'a final release follows its rc: '.$final->getErrorOutput());
        $this->assertStringContainsString('Final release of 1.1.0-rc.1', file_get_contents("$t/CHANGELOG.md"));
    }

    public function test_release_script_refuses_an_empty_changelog(): void
    {
        $t = $this->releaseTree('1.0.0', '');

        $p = $this->release($t, '1.1.0');

        $this->assertNotSame(0, $p->getExitCode());
        $this->assertStringContainsString('Nothing is listed under [Unreleased]', $p->getErrorOutput());
        $this->assertSame("1.0.0\n", file_get_contents("$t/VERSION"));
    }

    public function test_workflow_notes_extraction_returns_exactly_the_release_section(): void
    {
        $t = $this->releaseTree('1.0.0');
        $this->release($t, '1.1.0');
        $yaml = Yaml::parseFile("{$this->root}/.github/workflows/release.yml");
        $step = collect($yaml['jobs']['publish']['steps'])->first(fn ($s) => str_contains($s['name'] ?? '', 'Release notes'));
        $cmd = str_replace('${{ needs.verify.outputs.version }}', '1.1.0', $step['run']);

        $p = $this->run_(['bash', '-c', $cmd], [], $t);

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $this->assertSame("- Something new\n\n", file_get_contents("$t/notes.md"));
    }

    // ---- release workflow --------------------------------------------------------

    public function test_release_workflow_verifies_before_publishing_and_never_moves_latest_for_prereleases(): void
    {
        $w = Yaml::parseFile("{$this->root}/.github/workflows/release.yml");
        $raw = file_get_contents("{$this->root}/.github/workflows/release.yml");

        $this->assertSame(['verify'], (array) $w['jobs']['image']['needs']);
        $this->assertSame(['verify'], (array) $w['jobs']['chart']['needs']);
        $this->assertEqualsCanonicalizing(['verify', 'image', 'chart', 'bundle'], $w['jobs']['publish']['needs']);
        $this->assertStringContainsString('migrations:lint', $raw);
        $this->assertStringContainsString('php artisan test', $raw);
        $this->assertStringContainsString('helm lint', $raw);
        $this->assertStringContainsString("value=latest,enable=\${{ needs.verify.outputs.prerelease == 'false' }}", $raw);
        $this->assertStringContainsString('prerelease: ${{ needs.verify.outputs.prerelease == \'true\' }}', $raw);
    }

    // ---- Docker image recipe ------------------------------------------------------

    public function test_dockerfile_builds_from_files_that_exist_and_prunes_before_the_autoloader(): void
    {
        $df = file_get_contents("{$this->root}/Dockerfile");

        preg_match_all('/^COPY (?!--from)(?:--\S+ )*(.+) \S+$/m', $df, $m);
        foreach ($m[1] as $sources) {
            foreach (preg_split('/\s+/', trim($sources)) as $src) {
                if ($src === '.') {
                    continue;
                }
                $this->assertFileExists("{$this->root}/{$src}", "Dockerfile COPY source missing: {$src}");
            }
        }
        $this->assertLessThan(strpos($df, 'dump-autoload'), strpos($df, 'prune-distribution.sh'), 'vendor-only tools must be removed before the classmap is built');
        $this->assertStringContainsString('--no-dev', $df);
        $this->assertStringContainsString('HEALTHCHECK', $df);
        $this->assertStringContainsString('EXPOSE 8080', $df);
        $this->assertStringContainsString('sodium', $df, 'licensing needs ext-sodium');
        $this->assertStringContainsString('APP_DEBUG=false', $df);
        $this->assertDoesNotMatchRegularExpression('/COPY[^\n]*\.env(\s|$)/', $df, 'secrets must never be baked in');
    }

    public function test_dockerignore_keeps_secrets_and_junk_out_of_the_build_context(): void
    {
        $ignore = file_get_contents("{$this->root}/.dockerignore");

        foreach (['.git', '.env', 'node_modules', 'vendor', 'storage/app/*'] as $must) {
            $this->assertMatchesRegularExpression('/^'.preg_quote($must, '/').'$/m', $ignore, $must);
        }
    }

    public function test_entrypoint_handles_every_role_and_fails_fast_without_an_app_key(): void
    {
        $sh = file_get_contents("{$this->root}/docker/prod/entrypoint.sh");

        foreach (['web)', 'queue)', 'scheduler)', 'migrate)', 'artisan)'] as $role) {
            $this->assertStringContainsString($role, $sh);
        }
        $this->assertSame(0, $this->run_(['sh', '-n', "{$this->root}/docker/prod/entrypoint.sh"])->getExitCode());
        $this->assertStringContainsString('APP_KEY is not set', $sh);
        $this->assertStringNotContainsString('migrate --force', str_replace('app:install', '', $sh), 'web/queue/scheduler must not migrate implicitly');
        $this->assertStringNotContainsString('migrate:fresh', $sh);
        $this->assertStringNotContainsString('app:init', $sh);
    }

    public function test_entrypoint_refuses_to_start_without_an_app_key(): void
    {
        $p = $this->run_(['sh', "{$this->root}/docker/prod/entrypoint.sh", 'web'], ['APP_KEY' => '']);

        $this->assertSame(1, $p->getExitCode());
        $this->assertStringContainsString('APP_KEY is not set', $p->getErrorOutput());
    }

    public function test_nginx_and_php_limits_agree_and_health_endpoint_does_not_touch_the_database(): void
    {
        $nginx = file_get_contents("{$this->root}/docker/prod/nginx.conf");
        $ini = file_get_contents("{$this->root}/docker/prod/php.ini");

        preg_match('/client_max_body_size (\d+)m/', $nginx, $n);
        preg_match('/upload_max_filesize = (\d+)M/', $ini, $u);
        $this->assertGreaterThanOrEqual((int) $u[1], (int) $n[1]);
        $this->assertStringContainsString('location = /healthz', $nginx);
        $this->assertStringContainsString('/fpm-ping', $nginx);
        $this->assertStringContainsString('display_errors = Off', $ini);
        $this->assertStringContainsString('ping.path = /fpm-ping', file_get_contents("{$this->root}/docker/prod/php-fpm.conf"));
    }

    // ---- compose stack --------------------------------------------------------------

    public function test_compose_starts_in_order_and_keeps_the_root_password_out_of_app_containers(): void
    {
        $c = Yaml::parseFile("{$this->root}/deploy/selfhost/docker-compose.yml");

        foreach (['web', 'queue', 'scheduler'] as $svc) {
            $this->assertSame('service_completed_successfully', $c['services'][$svc]['depends_on']['migrate']['condition'], $svc);
        }
        $this->assertSame('service_healthy', $c['services']['migrate']['depends_on']['db']['condition']);
        $this->assertSame('no', $c['services']['migrate']['restart']);
        $this->assertArrayNotHasKey('ports', $c['services']['db'], 'the database must not be published');
        $this->assertArrayNotHasKey('env_file', $c['x-app']);
        $this->assertArrayNotHasKey('DB_ROOT_PASSWORD', $c['x-app']['environment']);
        $this->assertSame('single', $c['x-app']['environment']['TENANCY_MODE']);
        $this->assertSame('false', $c['x-app']['environment']['APP_DEBUG']);
    }

    public function test_every_variable_compose_requires_is_documented_in_the_env_example(): void
    {
        $compose = file_get_contents("{$this->root}/deploy/selfhost/docker-compose.yml");
        $env = file_get_contents("{$this->root}/deploy/selfhost/.env.example");

        preg_match_all('/\$\{([A-Z_]+):\?/', $compose, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $var) {
            $this->assertMatchesRegularExpression('/^#?\s*'.$var.'=/m', $env, "{$var} is required by compose but missing from .env.example");
        }
    }

    // ---- what ships ------------------------------------------------------------------

    public function test_excludes_list_removes_the_vendor_only_license_tools(): void
    {
        $list = file_get_contents("{$this->root}/deploy/dist-excludes.txt");

        $this->assertStringContainsString('app/Console/Commands/LicenseKeygen.php', $list);
        $this->assertStringContainsString('app/Console/Commands/LicenseIssue.php', $list);
        $this->assertMatchesRegularExpression('/^tests$/m', $list);
    }

    public function test_prune_script_deletes_listed_paths_and_refuses_unsafe_ones(): void
    {
        $d = $this->tmp();
        mkdir("$d/keep");
        mkdir("$d/gone/sub", 0777, true);
        touch("$d/keep/a.php");
        touch("$d/gone/sub/b.php");
        file_put_contents("$d/list.txt", "# comment\n\ngone\nmissing.txt\n");

        $p = $this->run_(['sh', "{$this->root}/scripts/prune-distribution.sh", $d, "$d/list.txt"]);

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $this->assertFileExists("$d/keep/a.php");
        $this->assertDirectoryDoesNotExist("$d/gone");

        foreach (['../escape', '/etc/passwd', 'a/../../b'] as $bad) {
            file_put_contents("$d/bad.txt", "$bad\n");
            $this->assertNotSame(0, $this->run_(['sh', "{$this->root}/scripts/prune-distribution.sh", $d, "$d/bad.txt"])->getExitCode(), $bad);
        }
    }

    public function test_release_bundle_contains_the_app_but_no_secrets_tests_or_vendor_tools(): void
    {
        $out = $this->tmp();

        $p = $this->run_(['bash', "{$this->root}/scripts/build-release-bundle.sh", '--skip-vendor', '--skip-assets', $out]);

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $version = trim(file_get_contents("{$this->root}/VERSION"));
        $tar = "$out/lawfirm-$version.tar.gz";
        $this->assertFileExists($tar);
        $this->assertFileExists("$tar.sha256");
        $this->assertSame(0, $this->run_(['sha256sum', '-c', basename($tar).'.sha256'], [], $out)->getExitCode(), 'checksum must verify');

        $list = $this->run_(['tar', '-tzf', $tar])->getOutput();
        foreach (['/artisan', '/VERSION', '/app/Console/Commands/AppInstall.php', '/app/Console/Commands/LicenseInstall.php', '/.env.example', '/deploy/helm/lawfirm/Chart.yaml'] as $must) {
            $this->assertStringContainsString("lawfirm-$version$must", $list, $must);
        }
        foreach (['LicenseIssue.php', 'LicenseKeygen.php', '/tests/', '/.git/', '/node_modules/', '/vendor/', '/tests-e2e/'] as $never) {
            $this->assertStringNotContainsString($never, $list, "bundle must not contain {$never}");
        }
        $this->assertDoesNotMatchRegularExpression('#lawfirm-[^/]+/\.env\n#', $list, 'the real .env must not ship');
    }

    public function test_the_app_still_boots_with_the_vendor_tools_pruned_out(): void
    {
        $d = $this->tmp();
        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'lang', 'resources', 'deploy', 'scripts'] as $dir) {
            $this->run_(['cp', '-r', "{$this->root}/$dir", "$d/$dir"]);
        }
        foreach (['artisan', 'VERSION', 'composer.json'] as $f) {
            copy("{$this->root}/$f", "$d/$f");
        }
        // vendor/composer must be a real copy so the autoloader resolves App\\ to THIS tree; packages can be symlinks.
        mkdir("$d/vendor");
        copy("{$this->root}/vendor/autoload.php", "$d/vendor/autoload.php");
        $this->run_(['cp', '-r', "{$this->root}/vendor/composer", "$d/vendor/composer"]);
        foreach (scandir("{$this->root}/vendor") as $entry) {
            if (! in_array($entry, ['.', '..', 'autoload.php', 'composer'], true)) {
                symlink("{$this->root}/vendor/$entry", "$d/vendor/$entry");
            }
        }
        foreach (['storage/framework/cache', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'storage/app'] as $s) {
            mkdir("$d/$s", 0777, true);
        }
        $this->run_(['sh', "$d/scripts/prune-distribution.sh", $d, "$d/deploy/dist-excludes.txt"]);
        $this->assertFileDoesNotExist("$d/app/Console/Commands/LicenseIssue.php");

        $env = ['APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_ENV' => 'production', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'];
        $p = $this->run_([PHP_BINARY, 'artisan', 'list', '--raw'], $env, $d);

        $this->assertSame(0, $p->getExitCode(), $p->getErrorOutput());
        $this->assertStringContainsString('app:install', $p->getOutput());
        $this->assertStringContainsString('license:install', $p->getOutput(), 'customers still install licenses');
        $this->assertStringNotContainsString('license:issue', $p->getOutput());
        $this->assertStringNotContainsString('license:keygen', $p->getOutput());
        $this->assertSame(trim(file_get_contents("{$this->root}/VERSION")), trim($this->run_([PHP_BINARY, 'artisan', 'app:version'], $env, $d)->getOutput()));
    }
}
