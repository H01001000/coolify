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
    private const ERROR_CODES = [
        'invalid_file', 'path_conflict', 'permission_denied', 'cancelled', 'timeout', 'write_failed',
        'invalid_template', 'template_limit', 'digest_mismatch', 'not_applied',
    ];

    public function supports(LocalFileVolume $storage): bool
    {
        return (bool) config('fabric.configuration_enabled')
            && ! $storage->is_host_file
            && ! $storage->is_directory
            && ! $storage->is_based_on_git
            && $storage->resource instanceof Application
            && (in_array($storage->resource->build_pack, ['dockerfile', 'dockerimage'], true) || filled($storage->resource->dockerfile));
    }

    public function saveTemplatesForDeployment(Application $application, int $pullRequestId = 0): void
    {
        $templates = $application->fileStorages->where('is_template', true);
        if ($templates->isNotEmpty() && $pullRequestId !== 0) {
            throw new RuntimeException('Templated file mounts do not support preview deployments yet.');
        }
        foreach ($templates as $storage) {
            $storage->saveStorageOnServer();
        }
    }

    public function save(LocalFileVolume $storage): array
    {
        if ($storage->is_template && ! $this->supports($storage)) {
            throw new RuntimeException('File templates require Fabric and a managed Dockerfile or Docker Image file mount.');
        }
        $application = $storage->resource;
        $primary = $application->destination->server;
        $servers = collect([$primary])->concat($application->additional_servers)->unique('id')->values();
        $path = confinePathToBase($application->workdir(), $storage->fs_path, 'managed file path');
        if ($path === $application->workdir() || $storage->is_binary || $storage->is_too_large) {
            throw new RuntimeException('This file cannot be synchronized through Fabric.');
        }

        $file = ['path' => $path, 'content' => (string) ($storage->content ?? '')];
        if (strlen($file['content']) > ($storage->is_template ? 65_536 : LocalFileVolume::MAX_CONTENT_SIZE)) {
            throw new RuntimeException('File exceeds the Fabric configuration size limit.');
        }
        if (filled($storage->chmod)) {
            if (! preg_match('/^[0-7]{3,4}$/', $storage->chmod) || octdec($storage->chmod) > 0777) {
                throw new RuntimeException('Fabric requires an octal file mode between 0000 and 0777.');
            }
            $file['mode'] = $storage->chmod;
        }
        if (filled($storage->chown)) {
            if (! preg_match('/^(\\d+)(?::(\\d+))?$/', $storage->chown, $owner)) {
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
            if (isset($node['metadata']) && ! is_array($node['metadata'])) {
                throw new RuntimeException('Fabric node metadata must be a JSON object.');
            }
            $targets[$server->id] = ['endpoint' => rtrim($endpoint, '/'), 'token' => $token, 'file' => $file];
        }

        if ($storage->is_template) {
            $inventory = $servers->map(fn ($server) => [
                'id' => (string) $server->id,
                'uuid' => (string) $server->uuid,
                'name' => (string) $server->name,
                'ip' => (string) $server->ip,
                'metadata' => (object) ($nodes[(string) $server->id]['metadata'] ?? []),
            ])->all();
            foreach ($inventory as $current) {
                $targetFile = $file;
                unset($targetFile['content']);
                $targetFile['template'] = [
                    'engine' => 'go-template/v1',
                    'source' => $file['content'],
                    'data' => [
                        'node' => $current,
                        'nodes' => $inventory,
                        'env' => $application->runtime_environment_variables->where('is_runtime', true)->map(fn ($env) => [
                            'key' => (string) $env->key,
                            'value' => (string) ($env->get_real_environment_variables_with_server($env->value, $application, $servers->firstWhere('id', $current['id'])) ?? ''),
                        ])->values()->all(),
                        'application' => ['uuid' => $application->uuid, 'name' => $application->name],
                    ],
                ];
                $targets[$current['id']]['file'] = $targetFile;
            }
            $responses = $this->send($application->uuid, $targets, 'render');
            $preflight = [];
            foreach ($targets as $serverId => $target) {
                $digest = $this->receipt($responses[$serverId] ?? null, $path, 'rendered');
                if (is_string($digest)) {
                    $targets[$serverId]['file']['expected_sha256'] = $digest;
                    $preflight[$serverId] = ['applied' => false, 'code' => 'not_applied'];
                } else {
                    $preflight[$serverId] = ['applied' => false, 'code' => $digest['code']];
                }
            }
            if (count(array_filter($targets, fn ($target) => ! isset($target['file']['expected_sha256']))) > 0) {
                throw new FabricConfigurationSyncException($preflight);
            }
        }

        $responses = $this->send($application->uuid, $targets, 'apply');
        $results = [];
        foreach ($targets as $serverId => $target) {
            $digest = $this->receipt($responses[$serverId] ?? null, $path, 'applied');
            $expected = $target['file']['expected_sha256'] ?? hash('sha256', $file['content']);
            $results[$serverId] = is_string($digest)
                ? ($digest === $expected ? ['applied' => true, 'sha256' => $digest] : ['applied' => false, 'code' => 'invalid_response'])
                : ['applied' => false, 'code' => $digest['code']];
        }
        if (collect($results)->contains(fn ($result) => ! $result['applied'])) {
            throw new FabricConfigurationSyncException($results);
        }

        return $results;
    }

    private function send(string $serviceId, array $targets, string $operation): array
    {
        $responses = [];
        foreach (array_chunk($targets, 8, true) as $batch) {
            $requests = [];
            foreach ($batch as $serverId => $target) {
                try {
                    $requests[$serverId] = Http::async()->withToken($target['token'])
                        ->acceptJson()->connectTimeout(5)->timeout(config('fabric.timeout', 30))
                        ->withOptions(['allow_redirects' => false, 'verify' => true])
                        ->post($target['endpoint'].'/v1/configurations/'.$operation, [
                            'service_id' => $serviceId, 'files' => [$target['file']],
                        ]);
                } catch (Throwable) {
                    $responses[$serverId] = null;
                }
            }
            foreach (Utils::settle($requests)->wait() as $serverId => $settled) {
                $responses[$serverId] = $settled['state'] === 'fulfilled' ? $settled['value'] : null;
            }
        }

        return $responses;
    }

    private function receipt(mixed $response, string $path, string $operation): string|array
    {
        try {
            if (! $response instanceof Response) {
                return ['code' => 'connection_failed'];
            }
            $body = $response->json();
            $receipt = is_array($body) ? ($body['files'][0] ?? null) : null;
            if ($response->status() === 200 && is_array($body) && ($body[$operation] ?? null) === true
                && is_array($receipt) && ($receipt[$operation] ?? null) === true
                && ($receipt['path'] ?? null) === $path && count($body['files']) === 1
                && is_string($receipt['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/', $receipt['sha256'])) {
                return $receipt['sha256'];
            }
            $code = is_array($receipt) ? ($receipt['code'] ?? '') : '';

            return ['code' => in_array($code, self::ERROR_CODES, true) ? $code : 'invalid_response'];
        } catch (Throwable) {
            return ['code' => 'invalid_response'];
        }
    }
}
