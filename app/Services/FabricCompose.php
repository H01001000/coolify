<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use JsonException;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/** Stateless client: resolved configuration in, Compose document out. */
class FabricCompose
{
    public const MAX_DOCUMENT_BYTES = 8 * 1024 * 1024;

    public function generate(int $serverId, array $specification): string
    {
        try {
            $nodes = json_decode(config('fabric.nodes_json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('Fabric node configuration is invalid.');
        }
        $node = is_array($nodes) ? ($nodes[(string) $serverId] ?? null) : null;
        $endpoint = is_array($node) ? ($node['endpoint'] ?? null) : null;
        $token = is_array($node) ? ($node['token'] ?? null) : null;
        $url = is_string($endpoint) ? parse_url($endpoint) : false;
        if (! is_string($token) || strlen($token) < 32 || ! is_array($url)
            || empty($url['host']) || isset($url['user']) || isset($url['pass'])
            || isset($url['query']) || isset($url['fragment'])
            || ! in_array($url['scheme'] ?? '', ['http', 'https'], true)
            || ($url['scheme'] !== 'https' && ! config('fabric.allow_insecure_http'))) {
            throw new RuntimeException('Invalid or missing Fabric Compose endpoint/token for server ID '.$serverId.'.');
        }
        if (strlen(json_encode($specification, JSON_THROW_ON_ERROR)) > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('Fabric Compose specification exceeds the size limit.');
        }
        try {
            $response = Http::withToken($token)->acceptJson()->connectTimeout(5)
                ->timeout(config('fabric.timeout', 30))
                ->withOptions(['allow_redirects' => false, 'verify' => true])
                ->post(rtrim($endpoint, '/').'/v1/compose/generate', $specification);
        } catch (Throwable) {
            // Do not expose request contents, tokens or remote error bodies in deployment logs.
            throw new RuntimeException('Fabric Compose RPC could not be reached.');
        }
        if ($response->status() !== 200 || strlen($response->body()) > self::MAX_DOCUMENT_BYTES * 2) {
            throw new RuntimeException('Fabric Compose RPC failed.');
        }
        try {
            $result = $response->json();
            $compose = is_array($result) ? ($result['compose'] ?? null) : null;
            $digest = is_array($result) ? ($result['sha256'] ?? null) : null;
            if (($result['schema_version'] ?? null) !== 1 || ! is_string($compose) || $compose === ''
                || strlen($compose) > self::MAX_DOCUMENT_BYTES || ! is_string($digest)
                || ! hash_equals(hash('sha256', $compose), $digest)) {
                throw new RuntimeException;
            }
            $document = Yaml::parse($compose);
            if (! is_array($document) || ! is_array($document['services'] ?? null)
                || array_keys($document['services']) !== [$specification['service_name']]) {
                throw new RuntimeException;
            }
        } catch (Throwable) {
            throw new RuntimeException('Fabric Compose RPC returned an invalid document.');
        }

        return $compose;
    }
}
