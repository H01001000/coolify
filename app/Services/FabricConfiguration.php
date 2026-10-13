<?php

namespace App\Services;

use App\Exceptions\FabricConfigurationSyncException;
use App\Models\Application;
use App\Models\LocalFileVolume;
use GuzzleHttp\Promise\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Throwable;

class FabricConfiguration
{
    public function supports(LocalFileVolume $storage): bool
    {
        return (bool) config('fabric.configuration_enabled')
            && ! $storage->is_host_file
            && ! $storage->is_directory
            && ! $storage->is_based_on_git
            && $storage->resource instanceof Application
            && ($storage->resource->build_pack === 'dockerfile' || filled($storage->resource->dockerfile));
    }

    public function save(LocalFileVolume $storage): array
    {
        $application = $storage->resource;
        $primary = $application->destination->server;
        $servers = collect([$primary])->concat($application->additional_servers)->unique('id')->values();
        $path = confinePathToBase($application->workdir(), $storage->fs_path, 'managed file path');
        if ($path === $application->workdir() || $storage->is_binary || $storage->is_too_large) {
            throw new RuntimeException('This file cannot be synchronized through Fabric.');
        }

        $file = ['path' => $path, 'content' => (string) ($storage->content ?? '')];
        if (strlen($file['content']) > LocalFileVolume::MAX_CONTENT_SIZE) {
            throw new RuntimeException('File exceeds the Fabric configuration size limit.');
        }
        if (filled($storage->chmod)) {
            if (! preg_match('/^[0-7]{3,4}$/', $storage->chmod) || octdec($storage->chmod) > 0777) {
                throw new RuntimeException('Fabric requires an octal file mode between 0000 and 0777.');
            }
            $file['mode'] = $storage->chmod;
        }
        if (filled($storage->chown)) {
            if (! preg_match('/^(\d+)(?::(\d+))?$/', $storage->chown, $owner)) {
                throw new RuntimeException('Fabric file ownership requires numeric UID and optional GID.');
            }
            foreach (array_slice($owner, 1) as $id) {
                if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 4294967294]]) === false) {
                    throw new RuntimeException('Fabric file ownership IDs are out of range.');
                }
            }
            $file['uid'] = (int) $owner[1];
            if (isset($owner[2])) {
                $file['gid'] = (int) $owner[2];
            }
        }

        try {
            $nodes = json_decode(config('fabric.nodes_json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Fabric node configuration is invalid.');
        }
        if (! is_array($nodes)) {
            throw new RuntimeException('Fabric node configuration must map server IDs to endpoints and tokens.');
        }

        // Validate every destination before sending any content. No silent SSH fallback.
        $targets = [];
        foreach ($servers as $server) {
            if ($server->team_id !== $primary->team_id) {
                throw new RuntimeException('Fabric destination belongs to a different team.');
            }
            $node = $nodes[(string) $server->id] ?? null;
            $endpoint = is_array($node) ? ($node['endpoint'] ?? '') : '';
            $token = is_array($node) ? ($node['token'] ?? '') : '';
            $url = is_string($endpoint) ? parse_url($endpoint) : false;
            if (! is_string($token) || strlen($token) < 32 || ! is_array($url)
                || empty($url['host']) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment'])
                || ! in_array($url['scheme'] ?? '', ['https', 'http'], true)
                || (($url['scheme'] ?? '') !== 'https' && ! config('fabric.allow_insecure_http'))) {
                throw new RuntimeException('Invalid or missing Fabric endpoint/token for server ID '.$server->id.'.');
            }
            $targets[$server->id] = ['endpoint' => rtrim($endpoint, '/'), 'token' => $token];
        }

        $responses = [];
        foreach (array_chunk($targets, 8, true) as $batch) {
            $requests = [];
            foreach ($batch as $serverId => $target) {
                try {
                    $requests[$serverId] = Http::async()->withToken($target['token'])
                        ->acceptJson()
                        ->connectTimeout(5)
                        ->timeout(config('fabric.timeout', 30))
                        ->withOptions(['allow_redirects' => false, 'verify' => true])
                        ->post($target['endpoint'].'/v1/configurations/apply', [
                            'service_id' => $application->uuid,
                            'files' => [$file],
                        ]);

                } catch (Throwable) {
                    $responses[$serverId] = null;
                }
            }
            foreach (Utils::settle($requests)->wait() as $serverId => $settled) {
                $responses[$serverId] = $settled['state'] === 'fulfilled' ? $settled['value'] : null;
            }
        }

        $digest = hash('sha256', $file['content']);
        $results = [];
        foreach ($targets as $serverId => $target) {
            $results[$serverId] = ['applied' => false, 'code' => 'rpc_failed'];
            try {
                $response = $responses[$serverId] ?? null;
                if (! $response instanceof Response) {
                    $results[$serverId]['code'] = 'connection_failed';

                    continue;
                }
                $body = $response->json();
                $receipt = is_array($body) ? ($body['files'][0] ?? null) : null;
                if ($response->status() === 200 && is_array($body) && ($body['applied'] ?? null) === true
                    && is_array($receipt) && ($receipt['applied'] ?? null) === true
                    && ($receipt['path'] ?? null) === $path && ($receipt['sha256'] ?? null) === $digest
                    && count($body['files']) === 1) {
                    $results[$serverId] = ['applied' => true, 'sha256' => $digest];
                } else {
                    $code = is_array($receipt) ? ($receipt['code'] ?? '') : '';
                    $results[$serverId]['code'] = in_array($code, [
                        'invalid_file', 'path_conflict', 'permission_denied', 'cancelled', 'timeout', 'write_failed',
                    ], true) ? $code : 'invalid_response';
                }
            } catch (Throwable) {
                // Client exceptions may contain request bodies or tokens; never expose them.
                $results[$serverId]['code'] = 'connection_failed';
            }
        }

        if (collect($results)->contains(fn ($result) => ! $result['applied'])) {
            throw new FabricConfigurationSyncException($results);
        }

        return $results;
    }
}
