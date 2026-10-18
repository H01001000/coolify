<?php

use App\Jobs\ServerStorageSaveJob;
use App\Livewire\Project\Service\FileStorage;
use App\Livewire\Project\Service\Storage;
use App\Livewire\Project\Shared\Storages\All;
use App\Livewire\Project\Shared\Storages\Show;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.maintenance.store' => 'array', 'cache.default' => 'array']);
    Bus::fake();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], ['id' => 0]));

    $this->team = Team::factory()->create();
    $this->admin = User::factory()->create();
    $this->admin->teams()->attach($this->team, ['role' => 'admin']);

    $keyId = DB::table('private_keys')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Key',
        'private_key' => 'test-key',
        'team_id' => $this->team->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $keyId,
    ]);

    StandaloneDocker::withoutEvents(function () {
        $this->destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $this->server->id, 'network' => 'coolify'],
            ['uuid' => (string) Str::uuid(), 'name' => 'test-docker']
        );
    });

    $this->project = Project::create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test Project',
        'team_id' => $this->team->id,
    ]);

    $this->environment = $this->project->environments()->first()
        ?? Environment::factory()->create(['project_id' => $this->project->id]);

    $this->application = Application::factory()->create([
        'uuid' => (string) Str::uuid(),
        'name' => 'Test App',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);

    $this->actingAs($this->admin);
    session(['currentTeam' => $this->team]);
});

test('livewire file storage rejects parent segments and does not create a local file volume', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/../../../../../../etc/example.conf')
        ->set('file_storage_content', 'owned')
        ->call('submitFileStorage')
        ->assertDispatched('error');

    expect(LocalFileVolume::query()->count())->toBe(0);
});

test('file mount modal shows the calculated host file path above the destination input', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->assertSeeText('Create a managed file on the host and mount it inside the container.')
        ->assertSeeText('Host file path')
        ->assertSeeText($this->application->workdir().'/')
        ->set('file_storage_path', '/etc/nginx/nginx.conf')
        ->assertSeeText($this->application->workdir().'/etc/nginx/nginx.conf')
        ->assertDontSeeText('Actual file mounted from the host system to the container.');
});

test('livewire file storage stores safe file mounts under the application configuration root', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/etc/nginx/nginx.conf')
        ->set('file_storage_content', 'server {}')
        ->call('submitFileStorage')
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    $volume = LocalFileVolume::query()->sole();

    expect($volume->mount_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->fs_path)->toBe(application_configuration_dir().'/'.$this->application->uuid.'/etc/nginx/nginx.conf')
        ->and($volume->is_directory)->toBeFalse();
});

test('livewire host file storage stores an existing host file path without managed content', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('host_file_storage_source', '/etc/nginx/nginx.conf')
        ->set('host_file_storage_destination', '/etc/nginx/nginx.conf')
        ->call('submitHostFileStorage')
        ->assertDispatched('success')
        ->assertDispatched('configurationChanged');

    $volume = LocalFileVolume::query()->sole();

    expect($volume->fs_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->mount_path)->toBe('/etc/nginx/nginx.conf')
        ->and($volume->content)->toBeNull()
        ->and($volume->is_host_file)->toBeTrue()
        ->and($volume->is_directory)->toBeFalse();

    Bus::assertNotDispatched(ServerStorageSaveJob::class);
});

test('livewire volume storage refreshes the storage list and configuration warning', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('name', 'data')
        ->set('mount_path', '/app/data')
        ->call('submitPersistentVolume')
        ->assertDispatched('success')
        ->assertNotDispatched('refreshStorages')
        ->assertNotDispatched('refreshVolumeList')
        ->assertDispatched('configurationChanged')
        ->assertSet('activeTab', 'volumes')
        ->assertSet('volumeCount', 1)
        ->assertSee($this->application->uuid.'-data');
});

test('adding another volume refreshes only the mounted volume list', function () {
    LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-first',
        'mount_path' => '/app/first',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('name', 'second')
        ->set('mount_path', '/app/second')
        ->call('submitPersistentVolume')
        ->assertDispatched('refreshVolumeList')
        ->assertNotDispatched('refreshStorages');
});

test('deleting a volume asks only the parent storage component to refresh its counts', function () {
    $volume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-data',
        'mount_path' => '/app/data',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    Livewire::test(All::class, ['resource' => $this->application])
        ->call('delete', $volume->id, 'password')
        ->assertDispatched('storageCountsChanged')
        ->assertNotDispatched('refreshStorages');
});

test('volume storage list shows volumes added after it was mounted', function () {
    $firstVolume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-first',
        'mount_path' => '/app/first',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $storageList = Livewire::test(All::class, ['resource' => $this->application])
        ->assertSee($firstVolume->name);

    $secondVolume = LocalPersistentVolume::create([
        'name' => $this->application->uuid.'-second',
        'mount_path' => '/app/second',
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    $storageList
        ->assertDontSee($secondVolume->name)
        ->call('refreshList')
        ->assertSee($secondVolume->name);
});

test('adding a volume switches to the volumes tab immediately', function () {
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->assertSet('activeTab', 'volumes')
        ->set('activeTab', 'directories')
        ->set('name', 'cache')
        ->set('mount_path', '/cache')
        ->call('submitPersistentVolume')
        ->assertSet('activeTab', 'volumes')
        ->assertSee($this->application->uuid.'-cache')
        ->assertDontSee('No directory mounts configured');
});

test('deleting a file mount refreshes the configuration warning', function () {
    $file = LocalFileVolume::create([
        'fs_path' => '/etc/nginx/nginx.conf',
        'mount_path' => '/etc/nginx/nginx.conf',
        'is_host_file' => true,
        'is_based_on_git' => false,
        'is_preview_suffix_enabled' => true,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $file])
        ->call('delete', 'password')
        ->assertDispatched('configurationChanged')
        ->assertDispatched('storageCountsChanged')
        ->assertNotDispatched('refreshStorages');

    expect($file->fresh())->toBeNull();
});

test('deleting a volume mount refreshes the configuration warning', function () {
    $database = StandalonePostgresql::create([
        'name' => 'test-postgres',
        'image' => 'postgres:15-alpine',
        'postgres_user' => 'postgres',
        'postgres_password' => 'password',
        'postgres_db' => 'postgres',
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
    ]);
    $volume = LocalPersistentVolume::create([
        'name' => $database->uuid.'-data',
        'mount_path' => '/var/lib/postgresql/data',
        'resource_id' => $database->id,
        'resource_type' => $database->getMorphClass(),
    ]);

    Livewire::test(Show::class, ['storage' => $volume, 'resource' => $database])
        ->call('delete', 'password')
        ->assertDispatched('configurationChanged');

    expect($volume->fresh())->toBeNull();
});

test('Fabric creation queues a verified fan-out rather than claiming remote completion', function (string $buildPack) {
    config(['fabric.configuration_enabled' => true]);
    $this->application->build_pack = $buildPack;
    $this->application->save();

    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/app/config.json')
        ->set('file_storage_content', '0')
        ->call('submitFileStorage')
        ->assertDispatched('success', 'File mount added. Synchronization to configured servers is queued.');

    $file = $this->application->fileStorages()->first();
    expect($file->content)->toBe('0');
    Bus::assertDispatched(ServerStorageSaveJob::class, fn ($job) => $job->localFileVolume->id === $file->id);
})->with(['dockerfile', 'dockerimage']);

test('Fabric editor retains desired content when an agent reports failure', function (string $buildPack) {
    config([
        'fabric.configuration_enabled' => true,
        'fabric.nodes_json' => json_encode([
            (string) $this->server->id => ['endpoint' => 'https://agent.test', 'token' => str_repeat('t', 32)],
        ]),
    ]);
    $this->application->build_pack = $buildPack;
    $this->application->save();
    $file = LocalFileVolume::create([
        'fs_path' => $this->application->workdir().'/app/config.json',
        'mount_path' => '/app/config.json',
        'content' => 'old',
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);
    Http::fake([
        '*' => Http::response([
            'applied' => false,
            'files' => [['path' => $file->fs_path, 'applied' => false, 'code' => 'path_conflict']],
        ], 207),
    ]);

    Livewire::test(FileStorage::class, ['fileStorage' => $file->fresh(), 'resource' => $this->application])
        ->set('content', 'new desired content')
        ->call('submit')
        ->assertDispatched('error');

    expect($file->fresh()->content)->toBe('new desired content');
})->with(['dockerfile', 'dockerimage']);

test('Fabric API content updates return verified completion or per-server failure', function (bool $fail, string $buildPack) {
    config([
        'fabric.configuration_enabled' => true,
        'fabric.nodes_json' => json_encode([
            (string) $this->server->id => ['endpoint' => 'https://agent.test', 'token' => str_repeat('t', 32)],
        ]),
    ]);
    $this->application->build_pack = $buildPack;
    $this->application->save();
    $file = LocalFileVolume::create([
        'fs_path' => $this->application->workdir().'/app/config.json',
        'mount_path' => '/app/config.json',
        'content' => 'old',
        'is_directory' => false,
        'resource_id' => $this->application->id,
        'resource_type' => $this->application->getMorphClass(),
    ]);
    Http::fake(function ($request) use ($fail) {
        $spec = $request['files'][0];

        return Http::response([
            'applied' => ! $fail,
            'files' => [[
                'path' => $spec['path'],
                'applied' => ! $fail,
                'sha256' => hash('sha256', $spec['content']),
                'code' => $fail ? 'permission_denied' : null,
            ]],
        ], $fail ? 207 : 200);
    });
    $token = $this->admin->createToken('fabric-api-test', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->patchJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'id' => $file->id, 'content' => '0',
        ]);
    if ($fail) {
        $response->assertStatus(502)->assertJsonPath('saved', true)
            ->assertJsonPath('synchronized', false)
            ->assertJsonPath('servers.'.$this->server->id.'.code', 'permission_denied');
    } else {
        $response->assertOk()->assertHeader('X-Fabric-File-Mount-Refresh', 'redeploy-required');
    }
    expect($file->fresh()->content)->toBe('0');
})->with([false, true])->with(['dockerfile', 'dockerimage']);

test('Fabric template creation retains encrypted source and queues rendering', function (string $buildPack) {
    config(['fabric.configuration_enabled' => true]);
    $this->application->update(['build_pack' => $buildPack]);
    $source = '{{range .env}}{{.key}}={{.value}}{{end}}';
    Livewire::test(Storage::class, ['resource' => $this->application])
        ->set('file_storage_path', '/app/config.txt')
        ->set('file_storage_content', $source)
        ->set('file_storage_is_template', true)
        ->call('submitFileStorage')
        ->assertDispatched('success');
    $file = $this->application->fileStorages()->first();
    expect($file->is_template)->toBeTrue()->and($file->content)->toBe($source);
    expect(DB::table('local_file_volumes')->where('id', $file->id)->value('content'))->not->toBe($source);
    Bus::assertDispatched(ServerStorageSaveJob::class);
    Livewire::test(FileStorage::class, ['fileStorage' => $file, 'resource' => $this->application])
        ->assertSet('isTemplate', true)
        ->call('loadStorageOnServer')
        ->assertDispatched('error');
    expect($file->fresh()->content)->toBe($source);
})->with(['dockerfile', 'dockerimage']);

test('Fabric template API passes node-specific environment lists without quoting or peer secrets', function () {
    config(['fabric.configuration_enabled' => true]);
    $this->application->update(['build_pack' => 'dockerimage']);
    $other = Server::factory()->create(['team_id' => $this->team->id, 'private_key_id' => $this->server->private_key_id]);
    $destination = StandaloneDocker::withoutEvents(fn () => StandaloneDocker::firstOrCreate(
        ['server_id' => $other->id, 'network' => 'coolify'],
        ['uuid' => (string) Str::uuid(), 'name' => 'template-test-docker'],
    ));
    $this->application->additional_networks()->attach($destination->id, ['server_id' => $other->id]);
    $values = [$this->server->id => "primary \"quoted\"\nvalue", $other->id => 'secondary-value'];
    $nodes = [];
    foreach ([$this->server, $other] as $server) {
        $nodes[(string) $server->id] = ['endpoint' => 'https://node-'.$server->id.'.test', 'token' => str_repeat('t', 32)];
        SharedEnvironmentVariable::create([
            'type' => 'server', 'server_id' => $server->id, 'team_id' => $this->team->id,
            'key' => 'TEMPLATE_SECRET', 'value' => $values[$server->id],
        ]);
    }
    config(['fabric.nodes_json' => json_encode($nodes)]);
    EnvironmentVariable::create([
        'key' => 'CONFIG_SECRET', 'value' => '{{server.TEMPLATE_SECRET}}',
        'is_runtime' => true, 'is_buildtime' => false,
        'resourceable_type' => Application::class, 'resourceable_id' => $this->application->id,
    ]);
    EnvironmentVariable::create([
        'key' => 'BUILD_ONLY', 'value' => 'build-only-credential',
        'is_runtime' => false, 'is_buildtime' => true,
        'resourceable_type' => Application::class, 'resourceable_id' => $this->application->id,
    ]);
    $source = '{{range .env}}{{if eq .key "CONFIG_SECRET"}}{{.value}}{{end}}{{end}}';
    $file = LocalFileVolume::create([
        'fs_path' => $this->application->workdir().'/config.txt', 'mount_path' => '/config.txt',
        'content' => 'old', 'is_directory' => false,
        'resource_type' => Application::class, 'resource_id' => $this->application->id,
    ]);
    Http::fake(function ($request) use ($values, $source) {
        $spec = $request['files'][0];
        $data = $spec['template']['data'];
        $id = $data['node']['id'];
        expect($spec['template']['source'])->toBe($source)
            ->and(collect($data['env'])->pluck('key')->all())->toBe(['CONFIG_SECRET'])
            ->and($data['env'][0]['value'])->toBe($values[$id])
            ->and(json_encode($data['nodes']))->not->toContain('secondary-value', 'CONFIG_SECRET', str_repeat('t', 32))
            ->and(count($data['nodes']))->toBe(2);
        $phase = str_ends_with($request->url(), '/render') ? 'rendered' : 'applied';
        $digest = hash('sha256', $values[$id]);
        if ($phase === 'applied') {
            expect($spec['expected_sha256'])->toBe($digest);
        }

        return Http::response([$phase => true, 'files' => [['path' => $spec['path'], $phase => true, 'sha256' => $digest]]]);
    });
    $token = $this->admin->createToken('template-test', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->patchJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'id' => $file->id, 'content' => $source, 'is_template' => true,
        ])->assertOk()->assertHeader('X-Fabric-File-Mount-Refresh', 'redeploy-required');
    expect($file->fresh()->is_template)->toBeTrue()->and($file->fresh()->content)->toBe($source);
    Http::assertSentCount(4);
});

test('Fabric template mutation requires application admin permission', function (string $role) {
    config(['fabric.configuration_enabled' => true]);
    $this->application->update(['build_pack' => 'dockerimage']);
    $user = User::factory()->create();
    $team = $role === 'member' ? $this->team : Team::factory()->create();
    $user->teams()->attach($team, ['role' => $role === 'member' ? 'member' : 'admin']);
    session(['currentTeam' => $team]);
    $token = $user->createToken('template-denied', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $response = $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'mount_path' => '/config.txt', 'content' => '{{.node.id}}', 'is_template' => true,
        ]);
    $response->assertStatus($role === 'member' ? 403 : 404);
    expect($this->application->fileStorages()->count())->toBe(0);
})->with(['member', 'other-team']);

test('Fabric template creation rejects host files directories and disabled configuration', function (string $mode) {
    config(['fabric.configuration_enabled' => $mode !== 'disabled']);
    $this->application->update(['build_pack' => 'dockerimage']);
    $token = $this->admin->createToken('template-invalid', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $fields = ['type' => 'file', 'mount_path' => '/config.txt', 'content' => '{{.node.id}}', 'is_template' => true];
    if ($mode === 'host') {
        $fields['is_host_file'] = true;
        $fields['fs_path'] = '/etc/example.conf';
    }
    if ($mode === 'directory') {
        $fields['is_directory'] = true;
        $fields['fs_path'] = '/data/example';
    }
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", $fields)->assertStatus(422);
    expect($this->application->fileStorages()->count())->toBe(0);
})->with(['host', 'directory', 'disabled']);

test('host file adoption changes only storage metadata and retains shared source paths', function () {
    config(['fabric.configuration_enabled' => true]);
    $this->application->update(['build_pack' => 'dockerimage']);
    $path = '/data/coolify/applications/other-app/shared/config.yml';
    $file = LocalFileVolume::create([
        'fs_path' => $path, 'mount_path' => '/etc/config.yml', 'content' => 'old saved copy',
        'is_directory' => false, 'resource_id' => $this->application->id, 'resource_type' => Application::class,
    ]);
    Http::preventStrayRequests();
    $token = $this->admin->createToken('adopt-host-file', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->patchJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'uuid' => $file->uuid, 'is_host_file' => true,
        ])->assertOk();
    expect($file->fresh()->is_host_file)->toBeTrue()->and($file->fresh()->content)->toBeNull()
        ->and($file->fresh()->fs_path)->toBe($path);
    Http::assertNothingSent();
});

test('host file adoption rejects reverse conversion incompatible modes and content', function (string $mode) {
    $file = LocalFileVolume::create([
        'fs_path' => $this->application->workdir().'/config.yml', 'mount_path' => '/etc/config.yml',
        'content' => $mode === 'reverse' ? null : 'original', 'is_directory' => $mode === 'directory',
        'is_host_file' => $mode === 'reverse', 'is_based_on_git' => $mode === 'git',
        'resource_id' => $this->application->id, 'resource_type' => Application::class,
    ]);
    $body = ['type' => 'file', 'uuid' => $file->uuid, 'is_host_file' => $mode !== 'reverse'];
    if ($mode === 'content') {
        $body['content'] = 'must not write';
    }
    $token = $this->admin->createToken('invalid-adoption', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->patchJson("/api/v1/applications/{$this->application->uuid}/storages", $body)->assertStatus(422);
    expect($file->fresh()->is_host_file)->toBe($mode === 'reverse')
        ->and($file->fresh()->content)->toBe($mode === 'reverse' ? null : 'original');
})->with(['reverse', 'directory', 'git', 'content']);

test('host file adoption requires resource admin access', function (string $role) {
    $file = LocalFileVolume::create([
        'fs_path' => $this->application->workdir().'/config.yml', 'mount_path' => '/etc/config.yml',
        'is_directory' => false, 'resource_id' => $this->application->id, 'resource_type' => Application::class,
    ]);
    $user = User::factory()->create();
    $team = $role === 'member' ? $this->team : Team::factory()->create();
    $user->teams()->attach($team, ['role' => $role === 'member' ? 'member' : 'admin']);
    session(['currentTeam' => $team]);
    $token = $user->createToken('denied-adoption', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->patchJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'uuid' => $file->uuid, 'is_host_file' => true,
        ])->assertStatus($role === 'member' ? 403 : 404);
    expect($file->fresh()->is_host_file)->toBeFalse();
})->with(['member', 'other-team']);

test('managed files can adopt an explicit source path inside their application', function () {
    $token = $this->admin->createToken('managed-source', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $path = $this->application->workdir().'/powerdns/config.yml';
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'mount_path' => '/etc/powerdns/config.yml', 'fs_path' => $path, 'content' => 'existing',
        ])->assertCreated();
    expect($this->application->fileStorages()->first()->fs_path)->toBe($path);
});

test('managed source path adoption rejects paths outside or at the application root', function (string $kind) {
    $base = $this->application->workdir();
    $path = match ($kind) {
        'outside' => '/etc/example.conf',
        'root' => $base,
        'sibling' => $base.'-other/config.yml',
    };
    $token = $this->admin->createToken('invalid-source', ['*'])->plainTextToken;
    Auth::forgetGuards();
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson("/api/v1/applications/{$this->application->uuid}/storages", [
            'type' => 'file', 'mount_path' => '/etc/config.yml', 'fs_path' => $path, 'content' => 'never written',
        ])->assertStatus(422);
    expect($this->application->fileStorages()->count())->toBe(0);
})->with(['outside', 'root', 'sibling']);
