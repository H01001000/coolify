<?php

namespace App\Jobs;

use App\Models\LocalFileVolume;
use App\Services\FabricConfiguration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerStorageSaveJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public function __construct(public LocalFileVolume $localFileVolume)
    {
        $this->onQueue('high');
        if (app(FabricConfiguration::class)->supports($localFileVolume)) {
            $count = $localFileVolume->resource->additional_servers->unique('id')->count() + 1;
            $this->timeout = max(60, (int) ceil($count / 8) * (int) config('fabric.timeout', 30) + 15);
        }
    }

    public function handle()
    {
        $this->localFileVolume->saveStorageOnServer();
    }
}
