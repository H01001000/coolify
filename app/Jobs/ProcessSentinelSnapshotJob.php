<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\SentinelSnapshotStore;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\InteractsWithUniqueJobs;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Laravel\Horizon\Contracts\Silenced;
use Throwable;

class ProcessSentinelSnapshotJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, InteractsWithUniqueJobs, Queueable, SerializesModels;

    // Overlap releases do not consume the single exception allowance.
    public $tries = 0;

    public $maxExceptions = 1;

    public $timeout = 30;

    // Recover if a queued wake-up is lost. Duplicate wake-ups read the same
    // latest version and are skipped after it has been processed.
    public $uniqueFor = 300;

    public Server $server;

    public function __construct(Server $server)
    {
        $this->server = $server->withoutRelations();
    }

    public static function enqueue(Server $server): void
    {
        $job = new static($server);
        try {
            dispatch($job);
        } catch (Throwable $exception) {
            // PendingDispatch can retain its unique lock when queue push throws.
            (new UniqueLock(Cache::store()))->release($job);
            $job->removeUniqueJobInformationFromContext($job);
            throw $exception;
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('push-server-update-'.$this->server->uuid))->shared()->releaseAfter(3)->expireAfter(60)];
    }

    public function handle(SentinelSnapshotStore $store): void
    {
        $snapshot = $store->latest($this->server);
        if ($snapshot === null) {
            $this->resetDispatchState();

            return;
        }
        if ($store->isProcessed($this->server, $snapshot['version'])) {
            return;
        }

        $processor = app()->makeWith(PushServerUpdateJob::class, [
            'server' => $this->server,
            'data' => $snapshot['data'],
            'fromLatestSnapshot' => true,
        ]);
        if ($this->job !== null) {
            $processor->setJob($this->job);
        }
        $processor->handle();
        // Serialize completion with publishers so a newer accepted version
        // gets a follow-up even if its state hash did not trigger a dispatch.
        Cache::lock('sentinel:push-lock:'.$this->server->id, 10)->block(5, function () use ($store, $snapshot): void {
            $store->markProcessed($this->server, $snapshot['version']);
            $latest = $store->latest($this->server);
            if ($latest !== null && $latest['version'] !== $snapshot['version']) {
                static::enqueue($this->server);
            }
        });
    }

    public function failed(?Throwable $exception): void
    {
        $this->resetDispatchState();
    }

    private function resetDispatchState(): void
    {
        // The next push must retry even if container state has not changed.
        Cache::forget('sentinel:push-hash:'.$this->server->id);
        Cache::forget('sentinel:push-force:'.$this->server->id);
    }
}
