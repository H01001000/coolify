# Fabric Compose generation (opt-in)

Set `FABRIC_COMPOSE_ENABLED=true` only after the deployment node runs a Fabric
agent supporting `POST /v1/compose/generate`. Reuse `FABRIC_NODES_JSON`, mapping
Coolify server IDs to HTTPS `endpoint` and bearer `token` (minimum 32 bytes).
TLS verification is required; `FABRIC_ALLOW_INSECURE_HTTP=true` is intended only
for isolated HTTP test endpoints. Redirects are never followed.

The new path replaces document assembly in `generate_compose_file`, used for
Coolify-generated single-application Compose files. Coolify resolves labels,
healthcheck commands, custom Docker options, environment and storage inputs;
Fabric assembles YAML and returns a SHA-256 receipt. Coolify verifies it, writes
the YAML into its existing helper, and continues the existing build/deploy flow.
Requests target the destination server, even when a separate build server is used.
Repository-supplied Compose stack handling is unchanged.

This RPC is stateless. It reads/writes no database or host file and makes no
Docker calls. No CRDB integration, deployment activation, build offload, new
helper ownership, or container reconciliation is included. The existing Coolify
model reads/writes outside the RPC retain their current behavior.

The flag defaults to false. With it enabled, missing node configuration, HTTP
failure, invalid YAML or a bad receipt fails the deployment. There is no silent
fallback. Set it false to return to the local generator.

The version-1 specification and response are documented in Fabric's
`docs/compose-rpc.md`. YAML formatting differs from Symfony; values and types
are compared semantically. The compatibility contract includes fixed-IP alias
behavior and PHP-style recursive custom-option merging.

Run the database-free client/integration tests:

```sh
php vendor/bin/pest tests/Unit/FabricComposeTest.php --compact
```

For real HTTP parity testing, start Fabric's optional
`TestComposeHTTPIntegrationServer` and set `FABRIC_COMPOSE_TEST_ENDPOINT` to its
printed loopback URL before running the same suite. No database or Docker daemon
is required by this suite; all Coolify models are in-memory stand-ins. The
cross-language cases are skipped when that endpoint is absent.
