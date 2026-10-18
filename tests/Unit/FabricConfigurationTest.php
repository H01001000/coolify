<?php

use App\Exceptions\FabricConfigurationSyncException;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\LocalFileVolume;
use App\Models\Server;
use App\Services\FabricConfiguration;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
        'fabric.configuration_enabled' => true,
        'fabric.allow_insecure_http' => false,
        'fabric.nodes_json' => json_encode([
            '1' => ['endpoint' => 'https://node-one.test', 'token' => str_repeat('a', 32)],
            '2' => ['endpoint' => 'https://node-two.test', 'token' => str_repeat('b', 32)],
            '3' => ['endpoint' => 'https://node-three.test', 'token' => str_repeat('c', 32)],
        ]),
    ]);
    Http::preventStrayRequests();

    $primary = new Server;
    $primary->id = 1;
    $primary->team_id = 10;
    $secondary = new Server;
    $secondary->id = 2;
    $secondary->team_id = 10;
    $third = new Server;
    $third->id = 3;
    $third->team_id = 10;
    $this->application = new Application;
    $this->application->uuid = 'configuration-test';
    $this->application->build_pack = 'dockerfile';
    $this->application->setRelation('destination', (object) ['server' => $primary]);
    $this->application->setRelation('additional_servers', collect([$secondary, $secondary, $third, $primary]));

    $this->storage = new LocalFileVolume;
    $this->storage->setRelation('resource', $this->application);
    $this->storage->fs_path = $this->application->workdir().'/app/config.json';
    $this->storage->content = 'desired content';
    $this->storage->is_directory = false;
    $this->storage->is_host_file = false;
    $this->storage->is_based_on_git = false;
    $this->fabric = new FabricConfiguration;
});

function fabricReceipt(Request $request, bool $applied = true, ?string $digest = null): array
{
    $file = $request['files'][0];

    return [
        'applied' => $applied,
        'files' => [[
            'path' => $file['path'],
            'applied' => $applied,
            'sha256' => $digest ?? hash('sha256', $file['content']),
            'code' => $applied ? null : 'path_conflict',
        ]],
    ];
}

it('fans out once per unique server and verifies receipts', function (string $buildPack) {
    $this->application->build_pack = $buildPack;
    Http::fake(fn (Request $request) => Http::response(fabricReceipt($request)));
    $results = $this->storage->saveStorageOnServer();

    expect(array_keys($results))->toBe([1, 2, 3])
        ->and(collect($results)->every(fn ($result) => $result['applied']))->toBeTrue();
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://node-two.test/v1/configurations/apply'
        && $request->hasHeader('Authorization', 'Bearer '.str_repeat('b', 32))
        && $request['service_id'] === 'configuration-test'
        && $request['files'][0]['content'] === 'desired content');
})->with(['dockerfile', 'dockerimage']);

it('sends empty and zero content without substituting touch', function (string $content, string $buildPack) {
    $this->application->build_pack = $buildPack;
    $this->storage->content = $content;
    Http::fake(fn (Request $request) => Http::response(fabricReceipt($request)));
    $this->fabric->save($this->storage);
    Http::assertSent(fn (Request $request) => $request['files'][0]['content'] === $content);
})->with(['', '0'])->with(['dockerfile', 'dockerimage']);

it('continues to other servers and reports partial failure without exposing content', function () {
    Http::fake(fn (Request $request) => Http::response(
        fabricReceipt($request, ! str_contains($request->url(), 'node-two')), str_contains($request->url(), 'node-two') ? 207 : 200,
    ));
    try {
        $this->fabric->save($this->storage);
        test()->fail('Expected synchronization failure');
    } catch (FabricConfigurationSyncException $e) {
        expect($e->results[1]['applied'])->toBeTrue()
            ->and($e->results[2])->toBe(['applied' => false, 'code' => 'path_conflict'])
            ->and($e->results[3]['applied'])->toBeTrue()
            ->and($e->getMessage())->not->toContain('desired content', str_repeat('b', 32));
    }
    Http::assertSentCount(3);
    expect($this->storage->content)->toBe('desired content');
});

it('rejects mismatched verification hashes', function () {
    Http::fake(fn (Request $request) => Http::response(fabricReceipt($request, true, str_repeat('0', 64))));
    expect(fn () => $this->fabric->save($this->storage))->toThrow(FabricConfigurationSyncException::class);
});

it('validates all destination configuration before sending any content', function () {
    config(['fabric.nodes_json' => json_encode(['1' => ['endpoint' => 'https://node-one.test', 'token' => str_repeat('a', 32)]])]);
    expect(fn () => $this->fabric->save($this->storage))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('does not opt in external files, directories, Git files or unrelated build packs', function (string $buildPack) {
    $this->application->build_pack = $buildPack;
    expect($this->fabric->supports($this->storage))->toBeTrue();
    foreach (['is_host_file', 'is_directory', 'is_based_on_git'] as $flag) {
        $this->storage->$flag = true;
        expect($this->fabric->supports($this->storage))->toBeFalse();
        $this->storage->$flag = false;
    }
    $this->application->build_pack = 'dockercompose';
    expect($this->fabric->supports($this->storage))->toBeFalse();
    $this->application->build_pack = 'dockerfile';
    config(['fabric.configuration_enabled' => false]);
    expect($this->fabric->supports($this->storage))->toBeFalse();
})->with(['dockerfile', 'dockerimage']);

it('rejects plaintext endpoints by default', function () {
    config(['fabric.nodes_json' => json_encode(['1' => ['endpoint' => 'http://node-one.test', 'token' => str_repeat('a', 32)]])]);
    expect(fn () => $this->fabric->save($this->storage))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('confines managed file paths to the application directory', function () {
    $this->storage->fs_path = '/etc/passwd';
    expect(fn () => $this->fabric->save($this->storage))->toThrow(Exception::class);
    Http::assertNothingSent();
});

it('passes numeric permissions and ownership through typed fields', function () {
    $this->storage->chmod = '0640';
    $this->storage->chown = '1000:988';
    Http::fake(fn (Request $request) => Http::response(fabricReceipt($request)));
    $this->fabric->save($this->storage);
    Http::assertSent(fn (Request $request) => $request['files'][0]['mode'] === '0640'
        && $request['files'][0]['uid'] === 1000 && $request['files'][0]['gid'] === 988);
});

it('reports unavailable nodes without leaking transport errors or skipping other targets', function () {
    Http::fake([
        'https://node-one.test/*' => Http::failedConnection(),
        '*' => fn (Request $request) => Http::response(fabricReceipt($request)),
    ]);
    try {
        $this->fabric->save($this->storage);
        test()->fail('Expected synchronization failure');
    } catch (FabricConfigurationSyncException $e) {
        expect($e->results[1]['code'])->toBe('connection_failed')
            ->and($e->results[2]['applied'])->toBeTrue()
            ->and($e->results[3]['applied'])->toBeTrue();
    }
});

it('rejects cross-team destinations before sending any content', function () {
    $this->application->additional_servers->first()->team_id = 99;
    expect(fn () => $this->fabric->save($this->storage))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('delegates template rendering to each node and preflights the entire fleet before applying', function (string $buildPack) {
    $this->application->build_pack = $buildPack;
    $this->application->setRelation('runtime_environment_variables', collect());
    $this->storage->is_template = true;
    $source = '{{range .nodes}}{{if ne .id $.node.id}}{{.ip}}{{end}}{{end}}';
    $this->storage->content = $source;
    $rendered = [];
    Http::fake(function (Request $request) use (&$rendered, $source) {
        $file = $request['files'][0];
        $data = $file['template']['data'];
        $id = $data['node']['id'];
        expect($file)->not->toHaveKey('content')
            ->and($file['template']['source'])->toBe($source)
            ->and($file['template']['engine'])->toBe('go-template/v1')
            ->and(array_column($data['nodes'], 'id'))->toBe(['1', '2', '3'])
            ->and($data['env'])->toBe([])
            ->and(json_encode($data))->not->toContain(str_repeat('a', 32), str_repeat('b', 32), str_repeat('c', 32));
        $digest = hash('sha256', 'rendered-for-'.$id);
        $phase = str_ends_with($request->url(), '/render') ? 'rendered' : 'applied';
        if ($phase === 'rendered') {
            $rendered[] = $id;
        } else {
            expect(count($rendered))->toBe(3)->and($file['expected_sha256'])->toBe($digest);
        }

        return Http::response([$phase => true, 'files' => [['path' => $file['path'], $phase => true, 'sha256' => $digest]]]);
    });
    $results = $this->storage->saveStorageOnServer();
    expect(count($results))->toBe(3)->and($this->storage->content)->toBe($source);
    Http::assertSentCount(6);
})->with(['dockerfile', 'dockerimage']);

it('does not apply anywhere when a template preflight fails or an agent is too old', function (string $failure) {
    $this->application->setRelation('runtime_environment_variables', collect());
    $this->storage->is_template = true;
    $this->storage->content = 'sensitive-template-source';
    Http::fake(function (Request $request) use ($failure) {
        expect($request->url())->toEndWith('/render');
        $file = $request['files'][0];
        if (str_contains($request->url(), 'node-two')) {
            return $failure === 'old-agent'
                ? Http::response(['error' => 'not_found'], 404)
                : Http::response(['rendered' => false, 'files' => [['path' => $file['path'], 'rendered' => false, 'code' => 'invalid_template']]], 422);
        }

        return Http::response(['rendered' => true, 'files' => [['path' => $file['path'], 'rendered' => true, 'sha256' => hash('sha256', 'output')]]]);
    });
    try {
        $this->storage->saveStorageOnServer();
        test()->fail('Expected preflight failure');
    } catch (FabricConfigurationSyncException $exception) {
        expect($exception->results[1]['code'])->toBe('not_applied')
            ->and($exception->results[2]['code'])->toBe($failure === 'old-agent' ? 'invalid_response' : 'invalid_template')
            ->and($exception->getMessage())->not->toContain('sensitive-template-source');
    }
    Http::assertSentCount(3);
})->with(['old-agent', 'bad-template']);

it('keeps ordinary files literal even when their contents look like a template', function () {
    $source = '{{.node.name}}';
    $this->storage->content = $source;
    Http::fake(function (Request $request) use ($source) {
        expect($request->url())->toEndWith('/apply');
        expect($request['files'][0]['content'])->toBe($source);

        return Http::response(fabricReceipt($request));
    });
    $this->storage->saveStorageOnServer();
    Http::assertSentCount(3);
});

it('rejects template server loads and disabled Fabric without replacing the source', function () {
    $this->storage->is_template = true;
    $source = '{{.node.name}}';
    $this->storage->content = $source;
    expect(fn () => $this->storage->loadStorageOnServer())->toThrow(RuntimeException::class);
    config(['fabric.configuration_enabled' => false]);
    expect(fn () => $this->storage->saveStorageOnServer())->toThrow(RuntimeException::class);
    expect($this->storage->content)->toBe($source);
    Http::assertNothingSent();
});

it('rejects template preview deployment before making requests', function () {
    $this->storage->is_template = true;
    $this->application->setRelation('fileStorages', collect([$this->storage]));
    expect(fn () => $this->fabric->saveTemplatesForDeployment($this->application, 1))->toThrow(RuntimeException::class);
    Http::assertNothingSent();
});

it('blocks template preview deployments before repository copying can touch host files', function () {
    $this->storage->is_template = true;
    $this->application->setRelation('fileStorages', collect([$this->storage]));
    $class = new ReflectionClass(ApplicationDeploymentJob::class);
    $job = $class->newInstanceWithoutConstructor();
    $class->getProperty('application')->setValue($job, $this->application);
    $class->getProperty('pull_request_id')->setValue($job, 17);
    $method = $class->getMethod('write_deployment_configurations');
    expect(fn () => $method->invoke($job))->toThrow(RuntimeException::class, 'preview');
    Http::assertNothingSent();
});
