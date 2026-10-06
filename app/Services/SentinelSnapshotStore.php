<?php

namespace App\Services;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class SentinelSnapshotStore
{
    private const RETENTION_SECONDS = 86400;

    public function key(Server $server): string
    {
        return 'sentinel:latest-snapshot:'.$server->id;
    }

    public function put(Server $server, array $data): void
    {
        Cache::putMany([
            $this->key($server) => [
                'version' => (string) Str::uuid(),
                'received_at' => now()->timestamp,
                'payload' => Crypt::encryptString(json_encode($data, JSON_THROW_ON_ERROR)),
            ],
            $this->key($server).':published' => true,
        ], self::RETENTION_SECONDS);
    }

    public function hasSnapshot(Server $server): bool
    {
        // Avoid fetching the large encrypted payload for each legacy queue item.
        return Cache::get($this->key($server).':published', false) === true;
    }

    public function latest(Server $server): ?array
    {
        $snapshot = Cache::get($this->key($server));
        if (! is_array($snapshot) || ! isset($snapshot['version'], $snapshot['received_at'], $snapshot['payload'])) {
            return null;
        }
        if ($snapshot['received_at'] < now()->timestamp - $server->waitBeforeDoingSshCheck()) {
            return null;
        }

        return [
            'version' => $snapshot['version'],
            'data' => json_decode(Crypt::decryptString($snapshot['payload']), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    public function isProcessed(Server $server, string $version): bool
    {
        return Cache::get($this->key($server).':processed') === $version;
    }

    public function markProcessed(Server $server, string $version): void
    {
        Cache::put($this->key($server).':processed', $version, self::RETENTION_SECONDS);
    }
}
