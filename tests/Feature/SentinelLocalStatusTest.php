<?php

use App\Actions\Shared\ComplexStatusCheck;
use App\Jobs\PushServerUpdateJob;
use App\Models\Application;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    ComplexStatusCheck::mock()->shouldNotReceive('handle');
});

function localSentinelStatusFixture(bool $primary, array $statuses): array
{
    $server = new Server;
    $server->id = 7;
    $server->uuid = 'reporting-server';
    $application = Mockery::mock(Application::class)->makePartial();
    $application->setRawAttributes([
        'id' => 42,
        'status' => 'exited',
        'destination_type' => StandaloneDocker::class,
        'destination_id' => 101,
        'additional_servers_count' => 1,
        'restart_limit_reached' => false,
    ], true);
    $application->setRelation('additional_servers', collect());
    $application->shouldReceive('stoppedAfterRestartLimit')->andReturn(false);
    $job = new PushServerUpdateJob($server, ['containers' => []]);
    (new ReflectionProperty($job, 'cachedDestinationIds'))->setValue($job, [collect([$primary ? 101 : 202]), collect()]);
    $job->applicationsById = collect(['42' => $application]);
    $job->allApplicationsWithAdditionalServers = collect([$application]);
    $job->applicationContainerStatuses = collect(['42' => collect($statuses)]);

    return [$application, $job];
}

it('updates a primary multi-server application from the Sentinel snapshot without SSH', function () {
    [$application, $job] = localSentinelStatusFixture(true, ['web' => 'running:healthy']);
    $application->shouldReceive('update')->once()->with(['status' => 'running:healthy']);
    (new ReflectionMethod($job, 'aggregateMultiContainerStatuses'))->invoke($job);
});

it('updates only the reporting additional destination from the Sentinel snapshot', function () {
    [$application, $job] = localSentinelStatusFixture(false, ['web' => 'running:healthy']);
    $application->shouldNotReceive('save');
    $application->shouldNotReceive('update');
    $relation = Mockery::mock();
    $application->shouldReceive('additional_servers')->andReturn($relation);
    $relation->shouldReceive('updateExistingPivot')->once()->with(7, ['status' => 'running:healthy']);
    (new ReflectionMethod($job, 'aggregateMultiContainerStatuses'))->invoke($job);
});

it('marks a missing additional destination exited without querying other servers', function () {
    [$application, $job] = localSentinelStatusFixture(false, []);
    $job->applicationContainerStatuses = collect();
    $application->shouldNotReceive('save');
    $application->shouldNotReceive('update');
    $relation = Mockery::mock();
    $application->shouldReceive('additional_servers')->andReturn($relation);
    $relation->shouldReceive('updateExistingPivot')->once()->with(7, ['status' => 'exited']);
    (new ReflectionMethod($job, 'updateAdditionalServersStatus'))->invoke($job);
});

it('does not re-inspect multi-server applications already represented in the snapshot', function () {
    [$application, $job] = localSentinelStatusFixture(true, ['web' => 'running:healthy']);
    $application->shouldNotReceive('update');
    (new ReflectionMethod($job, 'updateAdditionalServersStatus'))->invoke($job);
});

it('does not infer missing destinations from an incomplete snapshot', function () {
    [$application, $job] = localSentinelStatusFixture(false, []);
    $job->data['snapshot'] = ['complete' => false];
    $job->applicationContainerStatuses = collect();
    $application->shouldNotReceive('additional_servers');
    (new ReflectionMethod($job, 'updateAdditionalServersStatus'))->invoke($job);
});

it('preserves restart and mixed-container states for additional destinations', function (array $statuses, string $expected) {
    [$application, $job] = localSentinelStatusFixture(false, $statuses);
    $application->shouldNotReceive('save');
    $application->shouldNotReceive('update');
    $relation = Mockery::mock();
    $application->shouldReceive('additional_servers')->andReturn($relation);
    $relation->shouldReceive('updateExistingPivot')->once()->with(7, ['status' => $expected]);
    (new ReflectionMethod($job, 'aggregateMultiContainerStatuses'))->invoke($job);
})->with([
    'restarting' => [['web' => 'restarting:unknown'], 'restarting:unknown'],
    'mixed' => [['web' => 'running:healthy', 'worker' => 'exited'], 'degraded:unhealthy'],
    'unknown health' => [['web' => 'running:unknown'], 'running:unknown'],
]);

it('preserves excluded-container handling on additional destinations', function () {
    [$application, $job] = localSentinelStatusFixture(false, ['web' => 'running:healthy', 'optional' => 'running:unhealthy']);
    $application->docker_compose_raw = "services:\n  web:\n    image: example\n  optional:\n    image: example\n    exclude_from_hc: true\n";
    $application->shouldNotReceive('save');
    $relation = Mockery::mock();
    $application->shouldReceive('additional_servers')->andReturn($relation);
    $relation->shouldReceive('updateExistingPivot')->once()->with(7, ['status' => 'running:healthy']);
    (new ReflectionMethod($job, 'aggregateMultiContainerStatuses'))->invoke($job);
});

it('does not rewrite an unchanged primary status even when other destinations differ', function () {
    [$application, $job] = localSentinelStatusFixture(true, ['web' => 'running:healthy']);
    $application->setRawAttributes(array_merge($application->getAttributes(), ['status' => 'running:healthy']), true);
    $application->shouldNotReceive('update');
    (new ReflectionMethod($job, 'aggregateMultiContainerStatuses'))->invoke($job);
});
