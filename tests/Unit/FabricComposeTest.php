<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Services\FabricCompose;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

// This suite deliberately has no Laravel bootstrap, database connection or remote executor.
class FabricComposeUnitCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $container = new Container;
        Container::setInstance($container);
        $container->instance('config', new Repository([
            'fabric' => ['compose_enabled' => true, 'nodes_json' => json_encode([
                '1' => ['endpoint' => 'https://node-one.test', 'token' => str_repeat('a', 32)],
            ]), 'allow_insecure_http' => false, 'timeout' => 30],
            'constants' => ['coolify' => ['version' => 'test']],
        ]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);
        Http::swap(new Factory);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Facade::clearResolvedInstances();
        parent::tearDown();
    }
}
uses(FabricComposeUnitCase::class);

function composeSpecification(): array
{
    return [
        'schema_version' => 1, 'container_name' => 'app-20261023', 'service_name' => 'app-20261023',
        'image' => 'example/app:commit', 'network' => 'coolify', 'restart' => 'unless-stopped',
        'limits' => (object) [], 'custom_options' => (object) [],
    ];
}
function composeReceipt(string $yaml): array
{
    return ['schema_version' => 1, 'compose' => $yaml, 'sha256' => hash('sha256', $yaml)];
}

it('sends resolved inputs to the authenticated stateless endpoint and verifies the returned YAML', function () {
    $yaml = "services:\n  app-20261023:\n    image: 'example/app:commit'\n";
    Http::fake(['https://node-one.test/v1/compose/generate' => Http::response(composeReceipt($yaml))]);
    expect((new FabricCompose)->generate(1, composeSpecification()))->toBe($yaml);
    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer '.str_repeat('a', 32))
        && $request['schema_version'] === 1 && $request['network'] === 'coolify');
});

it('rejects invalid receipts without exposing remote bodies', function (array $receipt) {
    Http::fake(fn () => Http::response($receipt));
    expect(fn () => (new FabricCompose)->generate(1, composeSpecification()))
        ->toThrow(RuntimeException::class, 'Fabric Compose RPC returned an invalid document.');
})->with([
    'missing fields' => [[]],
    'wrong digest' => [['schema_version' => 1, 'compose' => 'secret remote content', 'sha256' => 'wrong']],
    'wrong version' => [[...composeReceipt('secret remote content'), 'schema_version' => 2]],
    'invalid YAML' => [composeReceipt('services: [')],
    'wrong service' => [composeReceipt("services:\n  other:\n    image: example/app\n")],
]);

it('fails closed on remote errors', function (int $status) {
    Http::fake(fn () => Http::response('remote body must not appear in logs', $status));
    expect(fn () => (new FabricCompose)->generate(1, composeSpecification()))->toThrow(RuntimeException::class, 'Fabric Compose RPC failed.');
})->with([301, 401, 422, 500]);

it('sanitizes connection failures', function () {
    Http::fake(fn () => throw new ConnectionException('secret request details'));
    expect(fn () => (new FabricCompose)->generate(1, composeSpecification()))->toThrow(RuntimeException::class, 'Fabric Compose RPC could not be reached.');
});

it('rejects missing or insecure endpoints before sending', function (string $nodes) {
    config(['fabric.nodes_json' => $nodes]);
    expect(fn () => (new FabricCompose)->generate(1, composeSpecification()))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
})->with(['{}', '{bad', json_encode([1 => ['endpoint' => 'http://node-one.test', 'token' => str_repeat('a', 32)]]), json_encode([1 => ['endpoint' => 'https://user:pass@node.test', 'token' => str_repeat('a', 32)]])]);

class FabricComposeApplication extends Application
{
    public array $data = [];

    public function __construct() {}

    public function getAttribute($key)
    {
        return $this->data[$key] ?? null;
    }

    public function setAttribute($key, $value)
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function project()
    {
        return (object) ['name' => 'project'];
    }

    public function main_port()
    {
        return [8080];
    }

    public function isHealthcheckDisabled(): bool
    {
        return false;
    }

    public function isLogDrainEnabled()
    {
        return (bool) ($this->data['log_drain_enabled'] ?? false);
    }

    public function fileStorages()
    {
        return new class
        {
            public function get()
            {
                return collect([(object) ['fs_path' => '/data/config', 'mount_path' => '/etc/app/config']]);
            }
        };
    }
}
class FabricComposeDeployment extends ApplicationDeploymentJob
{
    public array $commands = [];

    public function __construct() {}

    public function execute_remote_command(...$commands): void
    {
        $this->commands = array_merge($this->commands, $commands);
    }
}

function composeDeployment(bool $swarm = false): FabricComposeDeployment
{
    $app = new FabricComposeApplication;
    $settings = new class
    {
        public bool $is_consistent_container_name_enabled = true;

        public bool $is_container_label_readonly_enabled = false;

        public bool $is_container_label_escape_enabled = false;

        public bool $is_gpu_enabled = false;

        public bool $is_swarm_only_worker_nodes = false;

        public bool $is_static = false;

        public string $gpu_driver = 'nvidia';

        public string $gpu_count = 'all';

        public string $gpu_options = '';

        public string $gpu_device_ids = '';

        public function gpuDeviceReservation(): array
        {
            return (new ApplicationSetting([
                'gpu_driver' => $this->gpu_driver,
                'gpu_count' => $this->gpu_count,
                'gpu_device_ids' => $this->gpu_device_ids,
                'gpu_options' => $this->gpu_options,
            ]))->gpuDeviceReservation();
        }

        public ?int $stop_grace_period = 700;

        public function stopGracePeriodSeconds(): int
        {
            return 700;
        }
    };
    $app->data = [
        'destination' => (object) ['network' => 'coolify'],
        'id' => 7, 'uuid' => 'app-uuid', 'name' => 'application', 'build_pack' => 'nixpacks', 'settings' => $settings,
        'environment' => (object) ['name' => 'production'], 'persistentStorages' => collect(),
        'ports_mappings_array' => ['127.0.0.1:8080:8080'], 'custom_network_aliases_array' => ['custom-alias'],
        'limits_memory' => '512m', 'limits_memory_swap' => 0, 'limits_memory_swappiness' => 60,
        'limits_memory_reservation' => 0, 'limits_cpus' => '0.5', 'limits_cpu_shares' => 1024,
        'custom_docker_run_options' => '--user 1000:1000 --group-add 123 --ip 10.0.0.25 --ip6 2001:db8::25',
        'health_check_type' => 'cmd', 'health_check_command' => 'curl -f http://localhost:8080/',
        'health_check_interval' => 5, 'health_check_timeout' => 2, 'health_check_retries' => 3, 'health_check_start_period' => 10,
    ];
    $server = Mockery::mock(Server::class);
    $server->shouldReceive('getSchemalessAttributes')->andReturn([]);
    $server->shouldReceive('getAttribute')->with('id')->andReturn(1);
    $server->shouldReceive('isSwarm')->andReturn($swarm);
    $server->shouldReceive('isLogDrainEnabled')->andReturnUsing(fn () => (bool) ($app->data['log_drain_enabled'] ?? false));
    $server->shouldReceive('isFluentBitLogDrainEnabled')->andReturnUsing(fn () => ($app->data['log_driver'] ?? '') === 'fluentd');
    $server->shouldReceive('getAttribute')->with('settings')->andReturn((object) [
        'logdrain_cloudwatch_region' => 'us-west-2',
        'logdrain_cloudwatch_group' => '/coolify/parity',
        'logdrain_cloudwatch_stream_prefix' => 'parity/',
    ]);
    $destination = Mockery::mock(StandaloneDocker::class);
    $destination->shouldReceive('getAttribute')->with('network')->andReturn('coolify');
    $queue = Mockery::mock(ApplicationDeploymentQueue::class);
    $queue->shouldReceive('refresh')->andReturnSelf();
    $queue->shouldReceive('getAttribute')->with('status')->andReturn('queued');
    $job = new FabricComposeDeployment;
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    foreach ([
        'application' => $app, 'application_deployment_queue' => $queue,
        'destination' => $destination, 'server' => $server, 'mainServer' => $server,
        'pull_request_id' => 0, 'container_name' => 'app-20261023', 'production_image_name' => 'example/app:commit',
        'deployment_uuid' => 'deployment-test', 'workdir' => '/artifacts/deployment-test',
        'configuration_dir' => '/data/coolify/applications/app-uuid', 'saved_outputs' => collect(),
    ] as $name => $value) {
        $reflection->getProperty($name)->setValue($job, $value);
    }

    return $job;
}

it('uses the RPC document in the existing helper without running or persisting a deployment', function () {
    $yaml = "services:\n  app-uuid:\n    image: example/app:commit\n";
    Http::fake(fn () => Http::response(composeReceipt($yaml)));
    $job = composeDeployment();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getMethod('generate_compose_file')->invoke($job);
    expect($reflection->getProperty('docker_compose')->getValue($job))->toBe($yaml);
    expect($reflection->getProperty('generated_docker_compose')->getValue($job))->toBe($yaml);
    expect(collect($job->commands)->last()['input'])->toBe($yaml);
    expect(json_encode($job->commands))->not->toContain('compose up');
    Http::assertSent(fn (Request $request) => $request['service_name'] === 'app-uuid'
        && $request['healthcheck']['test'][0] === 'CMD-SHELL'
        && $request['volumes'][0] === '/data/config:/etc/app/config'
        && $request['stop_grace_period'] === '700s'
        && data_get($request['custom_options'], 'ip.0') === '10.0.0.25');
});

it('retains the legacy generator when the integration is disabled', function () {
    config(['fabric.compose_enabled' => false]);
    $job = composeDeployment();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $reflection->getMethod('generate_compose_file')->invoke($job);
    $service = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job))['services']['app-uuid'];
    expect($service['container_name'])->toBe('app-20261023')->and($service['stop_grace_period'])->toBe('700s');
    Http::assertNothingSent();
});

it('matches the legacy generator through a real Fabric HTTP server', function (string $scenario) {
    $endpoint = getenv('FABRIC_COMPOSE_TEST_ENDPOINT');
    if (! $endpoint) {
        $this->markTestSkipped('Set FABRIC_COMPOSE_TEST_ENDPOINT to a loopback-only Fabric RPC test server.');
    }
    if (! preg_match('#^http://127\.0\.0\.1:[0-9]+$#', $endpoint)) {
        throw new RuntimeException('Test endpoint must be loopback-only.');
    }
    config(['fabric.nodes_json' => json_encode([1 => ['endpoint' => $endpoint, 'token' => str_repeat('a', 32)]]), 'fabric.allow_insecure_http' => true]);
    Http::swap(new Factory);
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = composeDeployment($scenario === 'swarm');
    $application = $reflection->getProperty('application')->getValue($job);
    if ($scenario === 'generated-ip') {
        $application->settings->is_consistent_container_name_enabled = false;
    } elseif ($scenario === 'no-options') {
        $application->custom_docker_run_options = '';
        $application->ports_mappings_array = [];
        $application->settings->stop_grace_period = null;
    } elseif ($scenario === 'custom-options') {
        $application->custom_docker_run_options = '--entrypoint "/bin/echo hello world" --cap-add NET_ADMIN --security-opt no-new-privileges:true --tmpfs /tmp:rw,size=64m';
    } elseif (in_array($scenario, ['gpu', 'gpu-options', 'gpu-device-ids'], true)) {
        $application->settings->is_gpu_enabled = true;
        $application->settings->gpu_driver = 'nvidia';
        $application->settings->gpu_count = 'all';
        $application->settings->gpu_options = $scenario === 'gpu-options' ? "foo=bar\nbaz=qux" : '';
        if ($scenario === 'gpu-device-ids') {
            $application->settings->gpu_count = '';
            $application->settings->gpu_device_ids = ' 0, 2 ';
        }
    } elseif (in_array($scenario, ['fluentd', 'cloudwatch'], true)) {
        $application->data['log_drain_enabled'] = true;
        $application->data['log_driver'] = $scenario;
    } elseif ($scenario === 'dockerfile-healthcheck') {
        $application->custom_healthcheck_found = true;
    } elseif ($scenario === 'named-volume') {
        $application->persistentStorages = collect([(object) [
            'name' => 'app-data', 'host_path' => '', 'mount_path' => '/data', 'is_preview_suffix_enabled' => true,
        ]]);
    } elseif ($scenario === 'swarm') {
        $application->swarm_replicas = 3;
        $application->swarm_placement_constraints = base64_encode("placement:\n  constraints:\n    - node.labels.zone == jp\n");
        $application->settings->is_swarm_only_worker_nodes = true;
        $application->custom_docker_run_options = '';
    }
    config(['fabric.compose_enabled' => false]);
    $reflection->getMethod('generate_compose_file')->invoke($job);
    $legacy = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job));
    config(['fabric.compose_enabled' => true]);
    $reflection->getMethod('generate_compose_file')->invoke($job);
    $remote = Yaml::parse($reflection->getProperty('docker_compose')->getValue($job));
    expect($remote)->toEqual($legacy);
})->with(['consistent-ip', 'generated-ip', 'no-options', 'custom-options', 'gpu', 'gpu-options', 'gpu-device-ids', 'fluentd', 'cloudwatch', 'dockerfile-healthcheck', 'named-volume', 'swarm']);

it('does not publish a document when the RPC fails', function () {
    Http::fake(fn () => Http::response('private remote error', 500));
    $job = composeDeployment();
    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);
    expect(fn () => $reflection->getMethod('generate_compose_file')->invoke($job))->toThrow(RuntimeException::class, 'Fabric Compose RPC failed.');
    expect(json_encode($job->commands))->not->toContain('docker-compose.yaml');
});
