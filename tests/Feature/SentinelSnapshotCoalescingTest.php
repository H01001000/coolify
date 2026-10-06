<?php

use App\Jobs\ProcessSentinelSnapshotJob;
use App\Jobs\PushServerUpdateJob;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Services\SentinelSnapshotStore;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Cache::flush();
    Queue::fake();
    $this->server = new Server;
    $this->server->id = 0;
    $this->server->uuid = 'instance-server';
    $this->server->setRelation('settings', new ServerSetting(['sentinel_push_interval_seconds' => 60]));
    $this->store = new SentinelSnapshotStore;
});

it('keeps only the latest encrypted snapshot and a payload-free wake-up job', function () {
    $old = ['containers' => [['name' => 'old', 'private_value' => 'keep-this-secret']]];
    $new = ['containers' => [['name' => 'new']], 'snapshot' => ['complete' => false]];
    $this->store->put($this->server, $old);
    $this->store->put($this->server, $new);
    expect($this->store->latest($this->server)['data'])->toBe($new);
    $cached = Cache::get($this->store->key($this->server));
    expect($cached['payload'])->not->toContain('containers')->not->toContain('\"name\":\"new\"');
    $job = new ProcessSentinelSnapshotJob($this->server);
    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class);
    expect(serialize($job))->not->toContain('keep-this-secret')->not->toContain('containers');
});

it('enqueues one wake-up per server while accepting newer data', function () {
    foreach (range(1, 100) as $n) {
        $this->store->put($this->server, ['containers' => [['name' => "update-$n"]]]);
        ProcessSentinelSnapshotJob::dispatch($this->server);
    }
    Queue::assertPushed(ProcessSentinelSnapshotJob::class, 1);
    expect($this->store->latest($this->server)['data']['containers'][0]['name'])->toBe('update-100');
});

it('processes the latest data and preserves an update arriving during processing', function () {
    $first = ['containers' => [['name' => 'first']]];
    $second = ['containers' => [['name' => 'second']]];
    $this->store->put($this->server, $first);
    ProcessSentinelSnapshotJob::dispatch($this->server);
    $job = Queue::pushed(ProcessSentinelSnapshotJob::class)->first();
    // Laravel releases this lock after overlap middleware, immediately before handle.
    (new UniqueLock(Cache::store()))->release($job);
    $job->server->setRelation('settings', $this->server->settings);
    $this->app->bind(PushServerUpdateJob::class, function ($app, $parameters) use ($first, $second) {
        expect($parameters['data'])->toBe($first);
        expect($parameters['fromLatestSnapshot'])->toBeTrue();
        $processor = Mockery::mock(PushServerUpdateJob::class);
        $processor->shouldReceive('handle')->once()->andReturnUsing(function () use ($second) {
            $this->store->put($this->server, $second);
            ProcessSentinelSnapshotJob::dispatch($this->server);
            ProcessSentinelSnapshotJob::dispatch($this->server);
        });

        return $processor;
    });
    $job->handle($this->store);
    Queue::assertPushed(ProcessSentinelSnapshotJob::class, 2);
    expect($this->store->latest($this->server)['data'])->toBe($second);
    expect($this->store->isProcessed($this->server, $this->store->latest($this->server)['version']))->toBeFalse();
});

it('does not repeat an already processed snapshot', function () {
    $this->store->put($this->server, ['containers' => []]);
    $snapshot = $this->store->latest($this->server);
    $this->store->markProcessed($this->server, $snapshot['version']);
    $this->app->bind(PushServerUpdateJob::class, fn () => throw new RuntimeException('Processor must not run'));
    $job = new ProcessSentinelSnapshotJob($this->server);
    $job->server->setRelation('settings', $this->server->settings);
    expect(fn () => $job->handle($this->store))->not->toThrow(Throwable::class);
});

it('does not apply stale snapshots after the heartbeat grace window', function () {
    $this->store->put($this->server, ['containers' => []]);
    $this->travel(181)->seconds();
    expect($this->store->latest($this->server))->toBeNull();
});

it('skips legacy queued payloads once a newer snapshot is available', function () {
    $this->store->put($this->server, ['containers' => []]);
    $legacy = new PushServerUpdateJob($this->server, ['containers' => []]);
    // An unsaved mock server would hit SQL if the old payload is processed.
    expect(fn () => $legacy->handle())->not->toThrow(Throwable::class);
});

it('makes an unchanged subsequent push eligible after a processing failure', function () {
    Cache::put('sentinel:push-hash:0', 'old-hash', 1000);
    Cache::put('sentinel:push-force:0', true, 1000);
    (new ProcessSentinelSnapshotJob($this->server))->failed(new RuntimeException('test failure'));
    expect(Cache::has('sentinel:push-hash:0'))->toBeFalse();
    expect(Cache::has('sentinel:push-force:0'))->toBeFalse();
});

it('keeps a pending wake-up unique while another snapshot is being processed', function () {
    $this->store->put($this->server, ['containers' => []]);
    ProcessSentinelSnapshotJob::dispatch($this->server);
    $job = Queue::pushed(ProcessSentinelSnapshotJob::class)->first();
    $middleware = $job->middleware()[0];
    $legacy = new PushServerUpdateJob($this->server, []);
    expect($middleware->getLockKey($job))->toBe($legacy->middleware()[0]->getLockKey($legacy));
    $processingLock = Cache::lock($middleware->getLockKey($job), 60);
    expect($processingLock->get())->toBeTrue();
    $underlying = Mockery::mock(Job::class);
    $underlying->shouldReceive('release')->once()->with(3);
    $job->setJob($underlying);
    $middleware->handle($job, fn () => throw new RuntimeException('Overlap must not enter processor'));
    ProcessSentinelSnapshotJob::dispatch($this->server);
    Queue::assertPushed(ProcessSentinelSnapshotJob::class, 1);
    $processingLock->release();
});

it('schedules a follow-up for a newer snapshot even without another dispatch attempt', function () {
    $this->store->put($this->server, ['containers' => [['name' => 'before']]]);
    ProcessSentinelSnapshotJob::dispatch($this->server);
    $job = Queue::pushed(ProcessSentinelSnapshotJob::class)->first();
    (new UniqueLock(Cache::store()))->release($job);
    $job->server->setRelation('settings', $this->server->settings);
    $this->app->bind(PushServerUpdateJob::class, function () {
        $processor = Mockery::mock(PushServerUpdateJob::class);
        $processor->shouldReceive('handle')->once()->andReturnUsing(function () {
            $this->store->put($this->server, ['containers' => [['name' => 'during']]]);
        });

        return $processor;
    });
    $job->handle($this->store);
    Queue::assertPushed(ProcessSentinelSnapshotJob::class, 2);
});

it('keeps independent pending work for different servers', function () {
    $other = clone $this->server;
    $other->id = 7;
    $other->uuid = 'other-server';
    foreach ([$this->server, $other] as $server) {
        $this->store->put($server, ['containers' => []]);
        ProcessSentinelSnapshotJob::dispatch($server);
        ProcessSentinelSnapshotJob::dispatch($server);
    }
    Queue::assertPushed(ProcessSentinelSnapshotJob::class, 2);
});

it('recovers when a queued wake-up is lost without retaining an orphan lock forever', function () {
    $this->store->put($this->server, ['containers' => [['name' => 'first']]]);
    ProcessSentinelSnapshotJob::enqueue($this->server);
    $this->travel(301)->seconds();
    $this->store->put($this->server, ['containers' => [['name' => 'latest']]]);
    ProcessSentinelSnapshotJob::enqueue($this->server);
    Queue::assertPushed(ProcessSentinelSnapshotJob::class, 2);
    expect($this->store->latest($this->server)['data']['containers'][0]['name'])->toBe('latest');
});
