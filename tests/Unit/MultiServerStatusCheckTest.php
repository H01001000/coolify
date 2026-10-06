<?php

use App\Actions\Docker\GetContainersStatus;
use App\Actions\Shared\ComplexStatusCheck;
use App\Events\ServiceChecked;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

it('checks multi-server applications using only the current server snapshot', function () {
    Event::fake([ServiceChecked::class]);
    Schema::create('application_previews', function ($table) {
        $table->id();
        $table->unsignedBigInteger('application_id');
        $table->unsignedBigInteger('pull_request_id');
        $table->softDeletes();
    });
    $server = Mockery::mock(Server::class)->makePartial();
    $server->id = 4;
    $server->setRelation('team', (object) ['id' => 1]);
    $server->shouldReceive('isFunctional')->andReturn(true);
    $server->shouldReceive('isSwarm')->andReturn(false);
    $applications = collect(range(1, 16))->map(function ($id) use ($server) {
        $application = new Application;
        $application->id = $id;
        $application->setRelation('additional_servers', collect([new Server]));
        ComplexStatusCheck::mock()->shouldReceive('handle')->once()
            ->with($application, $server, Mockery::on(fn ($snapshot) => $snapshot->count() === 1
                && $snapshot->first()['Config']['Labels']['coolify.applicationId'] === (string) $id));

        return $application;
    });
    $server->shouldReceive('applications')->once()->andReturn($applications);
    $snapshot = collect(range(1, 16))->map(fn ($id) => ['Config' => ['Labels' => ['coolify.applicationId' => (string) $id, 'coolify.pullRequestId' => '0']], 'State' => ['Running' => true]]);
    $snapshot->push(['Config' => ['Labels' => ['coolify.applicationId' => '1', 'coolify.pullRequestId' => '123']]]);
    $snapshot->push(['Config' => ['Labels' => ['coolify.applicationId' => '999', 'coolify.pullRequestId' => '0']]]);
    $snapshot->push(['Config' => ['Labels' => ['coolify.applicationId' => '1', 'coolify.pullRequestId' => '0']], 'State' => ['Running' => false, 'Status' => 'exited']]);
    $server->shouldReceive('getContainers')->once()->andReturn(['containers' => $snapshot, 'containerReplicates' => null]);
    $server->shouldReceive('databases')->andReturn(collect());
    $server->shouldReceive('previews')->andReturn(collect());
    $services = Mockery::mock();
    $services->shouldReceive('get')->andReturn(collect());
    $server->shouldReceive('services')->andReturn($services);

    (new GetContainersStatus)->handle($server);
    Event::assertDispatched(ServiceChecked::class);
});

it('aggregates a supplied snapshot without querying other destinations', function (bool $primary, array $containers, string $expected) {
    $main = Mockery::mock(Server::class)->makePartial();
    $main->id = 1;
    $extra = Mockery::mock(Server::class)->makePartial();
    $extra->id = 2;
    $current = $primary ? $main : $extra;
    $current->shouldReceive('isFunctional')->andReturn(true);
    $application = Mockery::mock(Application::class)->makePartial();
    $application->id = 42;
    $application->setRelation('destination', (object) ['server' => $main]);
    $application->setRelation('additional_servers', collect([$extra]));
    $application->status = 'starting:unknown';
    if ($primary) {
        $application->shouldReceive('update')->once()->with(['status' => $expected]);
    } else {
        $relation = Mockery::mock();
        $application->shouldReceive('additional_servers')->andReturn($relation);
        $relation->shouldReceive('wherePivot')->with('server_id', 2)->andReturnSelf();
        $relation->shouldReceive('first')->andReturn((object) ['pivot' => (object) ['status' => 'starting:unknown']]);
        $relation->shouldReceive('updateExistingPivot')->once()->with(2, ['status' => $expected]);
        $application->shouldNotReceive('update');
    }
    (new ComplexStatusCheck)->handle($application, $current, collect($containers));
    expect($application->additional_servers)->toHaveCount(1);
})->with([
    'primary healthy' => [true, [['State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']]]], 'running:healthy'],
    'extra healthy' => [false, [['State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']]]], 'running:healthy'],
    'primary empty' => [true, [], 'exited'],
    'extra empty' => [false, [], 'exited'],
    'mixed containers' => [false, [['State' => ['Status' => 'running', 'Health' => ['Status' => 'healthy']]], ['State' => ['Status' => 'restarting']]], 'degraded:unhealthy'],
]);

it('does not check applications when the server snapshot is unavailable', function () {
    $server = Mockery::mock(Server::class)->makePartial();
    $server->shouldReceive('isFunctional')->andReturn(true);
    $server->shouldReceive('applications')->andReturn(collect([new Application]));
    $server->shouldReceive('getContainers')->once()->andReturn(['containers' => null, 'containerReplicates' => null]);
    ComplexStatusCheck::mock()->shouldNotReceive('handle');

    (new GetContainersStatus)->handle($server);
});

it('keeps the default all-destination check without mutating the relationship', function () {
    $main = Mockery::mock(Server::class)->makePartial();
    $main->id = 1;
    $main->shouldReceive('isFunctional')->once()->andReturn(false);
    $extra = Mockery::mock(Server::class)->makePartial();
    $extra->id = 2;
    $extra->shouldReceive('isFunctional')->once()->andReturn(false);
    $application = Mockery::mock(Application::class)->makePartial();
    $application->setRelation('destination', (object) ['server' => $main]);
    $application->setRelation('additional_servers', collect([$extra]));
    $application->shouldReceive('update')->once()->with(['status' => 'exited']);
    $relation = Mockery::mock();
    $application->shouldReceive('additional_servers')->andReturn($relation);
    $relation->shouldReceive('updateExistingPivot')->once()->with(2, ['status' => 'exited']);

    (new ComplexStatusCheck)->handle($application);
    expect($application->additional_servers)->toHaveCount(1);
});
