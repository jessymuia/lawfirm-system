<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/**
 * PLAT-5: renders deploy/helm/lawfirm with scripts/helm-render (a minimal stand-in for `helm template`) and checks
 * the manifests. This is NOT `helm lint`: the release workflow runs the real Helm. It exists so the chart is never
 * shipped unrendered. Skipped when Go is not installed.
 */
class HelmChartRenderTest extends TestCase
{
    private static ?string $bin = null;

    private const REQUIRED = ['database' => ['host' => 'db.internal'], 'secrets' => ['appKey' => 'base64:SECRETKEYVALUE', 'dbPassword' => 'SECRETDBPASS']];

    public static function setUpBeforeClass(): void
    {
        $go = trim((string) @shell_exec('command -v go 2>/dev/null')) ?: (is_executable('/usr/local/go/bin/go') ? '/usr/local/go/bin/go' : '');
        if ($go === '') {
            return;
        }
        $bin = sys_get_temp_dir().'/helm-render-'.getmypid();
        $p = new Process([$go, 'build', '-o', $bin, '.'], dirname(__DIR__, 2).'/scripts/helm-render', ['GOFLAGS' => '-mod=mod', 'HOME' => sys_get_temp_dir(), 'GOCACHE' => sys_get_temp_dir().'/gocache']);
        $p->run();
        self::$bin = $p->isSuccessful() ? $bin : null;
    }

    protected function setUp(): void
    {
        if (! self::$bin) {
            $this->markTestSkipped('Go toolchain not available to build the chart renderer.');
        }
    }

    /** @return array{0: array<int,array>, 1: string, 2: int} manifests, stderr, exit code */
    private function render(array $overrides = []): array
    {
        $chart = dirname(__DIR__, 2).'/deploy/helm/lawfirm';
        $values = array_replace_recursive(Yaml::parseFile("{$chart}/values.yaml"), self::REQUIRED, $overrides);
        $file = tempnam(sys_get_temp_dir(), 'vals');
        file_put_contents($file, json_encode($values, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES));
        $p = new Process([self::$bin, $chart, $file, 'rel']);
        $p->run();
        unlink($file);

        $docs = [];
        if ($p->isSuccessful()) {
            foreach (preg_split('/^---$/m', $p->getOutput()) as $chunk) {
                if (trim($chunk) !== '') {
                    $docs[] = Yaml::parse($chunk);
                }
            }
        }

        return [$docs, $p->getErrorOutput(), $p->getExitCode()];
    }

    private function kind(array $docs, string $kind, ?string $suffix = null): array
    {
        $found = array_values(array_filter($docs, fn ($d) => ($d['kind'] ?? '') === $kind && ($suffix === null || str_ends_with($d['metadata']['name'], $suffix))));
        $this->assertCount(1, $found, "expected exactly one {$kind}".($suffix ? " *{$suffix}" : ''));

        return $found[0];
    }

    private function env(array $deployment): array
    {
        $c = $deployment['spec']['template']['spec']['containers'][0];

        return array_column($c['env'], null, 'name');
    }

    public function test_default_install_renders_every_expected_object(): void
    {
        [$docs, $err, $code] = $this->render();

        $this->assertSame(0, $code, $err);
        $kinds = array_count_values(array_column($docs, 'kind'));
        ksort($kinds);
        $this->assertSame(['Deployment' => 3, 'Job' => 1, 'PersistentVolumeClaim' => 1, 'Secret' => 1, 'Service' => 1], $kinds);
    }

    public function test_selectors_match_pod_labels_and_service_targets_web(): void
    {
        [$docs] = $this->render();

        foreach (array_filter($docs, fn ($d) => $d['kind'] === 'Deployment') as $d) {
            $selector = $d['spec']['selector']['matchLabels'];
            $labels = $d['spec']['template']['metadata']['labels'];
            $this->assertSame($selector, array_intersect_key($labels, $selector), $d['metadata']['name'].': selector must match pod labels');
        }
        $web = $this->kind($docs, 'Deployment', '-web');
        $svc = $this->kind($docs, 'Service');
        $this->assertSame($svc['spec']['selector'], array_intersect_key($web['spec']['template']['metadata']['labels'], $svc['spec']['selector']));
        $this->assertSame('http', $svc['spec']['ports'][0]['targetPort']);
    }

    public function test_web_container_serves_8080_with_health_probes(): void
    {
        [$docs] = $this->render();
        $c = $this->kind($docs, 'Deployment', '-web')['spec']['template']['spec']['containers'][0];

        $this->assertSame(8080, $c['ports'][0]['containerPort']);
        $this->assertSame('http', $c['ports'][0]['name']);
        $this->assertSame('/healthz', $c['readinessProbe']['httpGet']['path']);
        $this->assertSame('/healthz', $c['livenessProbe']['httpGet']['path']);
        $this->assertSame(['web'], $c['args']);
    }

    public function test_each_role_runs_its_own_entrypoint_role(): void
    {
        [$docs] = $this->render();

        $this->assertSame(['queue'], $this->kind($docs, 'Deployment', '-queue')['spec']['template']['spec']['containers'][0]['args']);
        $this->assertSame(['scheduler'], $this->kind($docs, 'Deployment', '-scheduler')['spec']['template']['spec']['containers'][0]['args']);
        $this->assertSame(['migrate'], $this->kind($docs, 'Job')['spec']['template']['spec']['containers'][0]['args']);
    }

    public function test_image_defaults_to_the_chart_app_version_and_can_be_overridden(): void
    {
        [$docs] = $this->render();
        $this->assertSame('ghcr.io/your-org/lawfirm:9.9.9-test', $this->kind($docs, 'Deployment', '-web')['spec']['template']['spec']['containers'][0]['image']);

        [$docs] = $this->render(['image' => ['tag' => '2.1.0']]);
        $this->assertSame('ghcr.io/your-org/lawfirm:2.1.0', $this->kind($docs, 'Job')['spec']['template']['spec']['containers'][0]['image']);
    }

    public function test_secrets_only_appear_in_the_secret_object(): void
    {
        [$docs] = $this->render();

        foreach ($docs as $d) {
            $text = json_encode($d);
            if ($d['kind'] === 'Secret') {
                $this->assertStringContainsString('SECRETKEYVALUE', $text);

                continue;
            }
            $this->assertStringNotContainsString('SECRETKEYVALUE', $text, $d['kind'].' leaks the app key');
            $this->assertStringNotContainsString('SECRETDBPASS', $text, $d['kind'].' leaks the db password');
        }
        $env = $this->env($this->kind($docs, 'Deployment', '-web'));
        $this->assertSame('APP_KEY', $env['APP_KEY']['valueFrom']['secretKeyRef']['key']);
        $this->assertSame('DB_PASSWORD', $env['DB_PASSWORD']['valueFrom']['secretKeyRef']['key']);
    }

    public function test_migration_runs_before_install_and_upgrade_and_the_secret_exists_before_it(): void
    {
        [$docs] = $this->render();
        $job = $this->kind($docs, 'Job');
        $secret = $this->kind($docs, 'Secret');

        $this->assertSame('pre-install,pre-upgrade', $job['metadata']['annotations']['helm.sh/hook']);
        $this->assertSame('pre-install,pre-upgrade', $secret['metadata']['annotations']['helm.sh/hook']);
        $this->assertLessThan((int) $job['metadata']['annotations']['helm.sh/hook-weight'], (int) $secret['metadata']['annotations']['helm.sh/hook-weight'], 'secret must be created before the job');
        $this->assertStringNotContainsString('hook-succeeded', $job['metadata']['annotations']['helm.sh/hook-delete-policy'], 'a generated admin password lives in the job logs');
        $this->assertSame('keep', $this->kind($docs, 'PersistentVolumeClaim')['metadata']['annotations']['helm.sh/resource-policy'], 'documents must survive uninstall');
    }

    public function test_the_migrate_job_does_not_need_the_storage_volume(): void
    {
        [$docs] = $this->render();

        $this->assertArrayNotHasKey('volumes', $this->kind($docs, 'Job')['spec']['template']['spec']);
    }

    public function test_rwo_storage_keeps_queue_and_scheduler_beside_web(): void
    {
        [$docs] = $this->render();

        foreach (['-queue', '-scheduler'] as $role) {
            $term = $this->kind($docs, 'Deployment', $role)['spec']['template']['spec']['affinity']['podAffinity']['requiredDuringSchedulingIgnoredDuringExecution'][0];
            $this->assertSame('kubernetes.io/hostname', $term['topologyKey']);
            $this->assertSame('web', $term['labelSelector']['matchLabels']['app.kubernetes.io/component']);
        }
    }

    public function test_rwx_storage_allows_scaling_web_without_affinity(): void
    {
        [$docs, $err, $code] = $this->render(['persistence' => ['accessMode' => 'ReadWriteMany'], 'web' => ['replicaCount' => 3]]);

        $this->assertSame(0, $code, $err);
        $this->assertSame(3, $this->kind($docs, 'Deployment', '-web')['spec']['replicas']);
        $this->assertArrayNotHasKey('affinity', $this->kind($docs, 'Deployment', '-queue')['spec']['template']['spec']);
    }

    public function test_scaling_web_on_a_rwo_volume_is_refused(): void
    {
        [, $err, $code] = $this->render(['web' => ['replicaCount' => 2]]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('ReadWriteMany', $err);
    }

    public function test_a_missing_database_host_is_refused(): void
    {
        [, $err, $code] = $this->render(['database' => ['host' => '']]);

        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('database.host is required', $err);
    }

    public function test_missing_secrets_are_refused_unless_an_existing_secret_is_named(): void
    {
        [, $err, $code] = $this->render(['secrets' => ['appKey' => '']]);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('secrets.appKey is required', $err);

        [$docs, $err, $code] = $this->render(['secrets' => ['appKey' => '', 'dbPassword' => ''], 'existingSecret' => 'my-secret']);
        $this->assertSame(0, $code, $err);
        $this->assertNotContains('Secret', array_column($docs, 'kind'));
        $this->assertSame('my-secret', $this->env($this->kind($docs, 'Deployment', '-web'))['APP_KEY']['valueFrom']['secretKeyRef']['name']);
    }

    public function test_ingress_is_off_by_default_and_renders_when_enabled(): void
    {
        [$docs] = $this->render();
        $this->assertNotContains('Ingress', array_column($docs, 'kind'));

        [$docs, $err] = $this->render(['ingress' => ['enabled' => true, 'host' => 'firm.example.org', 'className' => 'nginx', 'tls' => ['secretName' => 'firm-tls']]]);
        $ing = $this->kind($docs, 'Ingress');
        $this->assertSame('firm.example.org', $ing['spec']['rules'][0]['host']);
        $this->assertSame('firm-tls', $ing['spec']['tls'][0]['secretName']);
        $this->assertSame('nginx', $ing['spec']['ingressClassName']);
    }

    public function test_persistence_can_use_an_existing_claim_or_be_disabled(): void
    {
        [$docs] = $this->render(['persistence' => ['existingClaim' => 'shared-docs']]);
        $this->assertNotContains('PersistentVolumeClaim', array_column($docs, 'kind'));
        $this->assertSame('shared-docs', $this->kind($docs, 'Deployment', '-web')['spec']['template']['spec']['volumes'][0]['persistentVolumeClaim']['claimName']);

        [$docs] = $this->render(['persistence' => ['enabled' => false]]);
        $this->assertArrayHasKey('emptyDir', $this->kind($docs, 'Deployment', '-web')['spec']['template']['spec']['volumes'][0]);
        $this->assertArrayNotHasKey('affinity', $this->kind($docs, 'Deployment', '-queue')['spec']['template']['spec']);
    }

    public function test_production_settings_are_fixed_in_every_role(): void
    {
        [$docs] = $this->render();

        foreach (array_filter($docs, fn ($d) => in_array($d['kind'], ['Deployment', 'Job'], true)) as $d) {
            $env = $this->env($d);
            $this->assertSame('production', $env['APP_ENV']['value']);
            $this->assertSame('false', $env['APP_DEBUG']['value']);
            $this->assertSame('single', $env['TENANCY_MODE']['value']);
            $this->assertSame('stderr_json', $env['LOG_CHANNEL']['value']);
            $this->assertSame('db.internal', $env['DB_HOST']['value']);
        }
        foreach (array_filter($docs, fn ($d) => $d['kind'] === 'Deployment') as $d) {
            $this->assertNotEmpty($d['spec']['template']['spec']['containers'][0]['resources']['requests'], 'resource requests are required');
        }
    }

    public function test_image_pull_secrets_are_passed_through(): void
    {
        [$docs] = $this->render(['imagePullSecrets' => [['name' => 'regcred']]]);

        $this->assertSame([['name' => 'regcred']], $this->kind($docs, 'Job')['spec']['template']['spec']['imagePullSecrets']);
        $this->assertSame([['name' => 'regcred']], $this->kind($docs, 'Deployment', '-web')['spec']['template']['spec']['imagePullSecrets']);
    }

    /** ENG-20: Redis for cache, sessions and the queue; workers scale out; heavy work can have its own workers. */
    public function test_redis_scaled_workers_and_a_bulk_lane(): void
    {
        [$docs] = $this->render();
        $queue = $this->kind($docs, 'Deployment', '-queue');
        $this->assertSame(['database', 'default,bulk'], [$this->env($queue)['QUEUE_CONNECTION']['value'], $this->env($queue)['QUEUE_WORKER_QUEUES']['value']]);
        $this->assertArrayNotHasKey('REDIS_HOST', $this->env($queue));

        [$docs, $err, $code] = $this->render(['redis' => ['host' => 'redis.internal'], 'secrets' => self::REQUIRED['secrets'] + ['redisPassword' => 'SECRETREDIS'],
            'queue' => ['replicaCount' => 4, 'lanes' => 'default'], 'bulkQueue' => ['enabled' => true, 'replicaCount' => 2]]);
        $this->assertSame(0, $code, $err);
        $queue = $this->kind($docs, 'Deployment', '-queue');
        $bulk = $this->kind($docs, 'Deployment', '-queue-bulk');
        $env = $this->env($queue);
        $this->assertSame(['redis', 'redis', 'redis', 'redis.internal', 'default'], [$env['QUEUE_CONNECTION']['value'], $env['CACHE_DRIVER']['value'],
            $env['SESSION_DRIVER']['value'], $env['REDIS_HOST']['value'], $env['QUEUE_WORKER_QUEUES']['value']]);
        $this->assertSame('REDIS_PASSWORD', $env['REDIS_PASSWORD']['valueFrom']['secretKeyRef']['key']);
        $this->assertSame([4, 2], [$queue['spec']['replicas'], $bulk['spec']['replicas']]);
        $this->assertSame(['queue'], $bulk['spec']['template']['spec']['containers'][0]['args']);
        $this->assertSame('bulk', $this->env($bulk)['QUEUE_WORKER_QUEUES']['value']);
        $this->assertSame($bulk['spec']['selector']['matchLabels'], array_intersect_key($bulk['spec']['template']['metadata']['labels'], $bulk['spec']['selector']['matchLabels']));
        $this->assertNotSame($queue['spec']['selector']['matchLabels'], $bulk['spec']['selector']['matchLabels'], 'the two worker sets do not adopt each other\'s pods');
        foreach (array_filter($docs, fn ($d) => $d['kind'] !== 'Secret') as $d) {
            $this->assertStringNotContainsString('SECRETREDIS', json_encode($d), $d['metadata']['name']);
        }
    }

    /** ENG-19: the search engine is chosen in values; its key only ever comes from the Secret. */
    public function test_search_engine_settings(): void
    {
        [$docs] = $this->render();
        $this->assertSame('database', $this->env($this->kind($docs, 'Deployment', '-web'))['SEARCH_ENGINE']['value']);

        [, $err, $code] = $this->render(['search' => ['engine' => 'meilisearch']]);
        $this->assertNotSame(0, $code, 'a host is required');
        $this->assertStringContainsString('search.meilisearchHost is required', $err);

        [$docs, $err, $code] = $this->render(['search' => ['engine' => 'meilisearch', 'meilisearchHost' => 'http://meili:7700'],
            'secrets' => self::REQUIRED['secrets'] + ['meilisearchKey' => 'SECRETMEILI']]);
        $this->assertSame(0, $code, $err);
        $env = $this->env($this->kind($docs, 'Deployment', '-queue'));
        $this->assertSame(['meilisearch', 'http://meili:7700', 'MEILISEARCH_KEY'], [$env['SEARCH_ENGINE']['value'], $env['MEILISEARCH_HOST']['value'],
            $env['MEILISEARCH_KEY']['valueFrom']['secretKeyRef']['key']]);
        foreach (array_filter($docs, fn ($d) => $d['kind'] !== 'Secret') as $d) {
            $this->assertStringNotContainsString('SECRETMEILI', json_encode($d), $d['metadata']['name']);
        }
    }
}
