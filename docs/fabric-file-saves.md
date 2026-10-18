# Fabric-backed managed file saves

This opt-in integration applies to editable, regular managed files on Dockerfile
applications (including inline Dockerfiles) and Docker Image applications.
Coolify remains authoritative for
file contents. Fabric only executes synchronous, authenticated node-local RPCs.

No migrations or per-server UI fields are required for this first integration.
Configure the controller's environment, then rebuild its Laravel configuration
cache through the normal installation workflow:

```dotenv
FABRIC_CONFIGURATION_ENABLED=true
FABRIC_NODES_JSON='{"0":{"endpoint":"https://agent-primary.example","token":"<node-specific-random-secret>"},"4":{"endpoint":"https://agent-secondary.example","token":"<node-specific-random-secret>"}}'
```

Keys are Coolify server IDs. Configure every primary/additional destination used
by opted-in applications. Tokens must match each node's FABRIC_RPC_TOKEN and
contain at least 32 bytes. Keep this environment file protected; no token is
returned in API results. HTTPS certificate verification is enabled, redirects
are disabled, and HTTP endpoints are rejected by default.
FABRIC_ALLOW_INSECURE_HTTP=true permits explicit local/development HTTP testing.

Each agent needs the stateless POST /v1/configurations/apply endpoint described in
Fabric's docs/configuration-rpc.md, with the appropriate allowed root and writable
mounts. Mount /data/coolify/applications at the same absolute path inside agentd.
Grant its OS identity the necessary write permissions; Docker socket group access
alone is insufficient.

## Behavior

- Create stores the encrypted file content and uses Coolify's existing encrypted
  high-priority ServerStorageSaveJob. The UI reports synchronization as queued.
- Editor Save persists desired content, then sends RPCs to the primary server
  and each unique additional server. Requests run concurrently in batches of eight.
- Every success must contain the exact requested path and SHA-256 content hash.
- API content updates follow the same route. Partial failures return HTTP 502,
  saved=true, synchronized=false and per-server outcomes.
- A successful API save adds X-Fabric-File-Mount-Refresh: redeploy-required.
- One failing node does not prevent attempts on other nodes. Errors expose only
  server IDs and stable error codes, never the request body or credentials.
- Partial failure keeps the desired content in Coolify; another Save retries it.
  There is no distributed rollback, stored per-node history, or automatic retry
  controller in Fabric.
- Existing host-file mounts, directories, Git-managed files, services and
  databases keep their existing behavior.
- The integration defaults off. When enabled but destination configuration is
  missing, it fails explicitly rather than silently writing only the primary
  host through SSH.

The agent correctly writes empty content and "0". It rejects symlinks, special
files and directory/file conflicts. An accidental directory created by an older
deployment must be inspected and repaired separately; Save will not recursively
delete it. Numeric UID and optional GID are supported; names must be converted
to numeric IDs by the operator.

Atomic replacement updates the host file, but existing individual-file bind
mounts may still reference the previous inode. Redeploy/recreate affected
containers to refresh these mounts. Save does not restart or deploy anything.

Deployment-time preparation is deliberately separate. Newly attached servers
receive the content only on the next Create/Save, and deploying before queued
creation finishes can still race it. More than eight destinations require
multiple request batches and may exceed an interactive request's timeout.

## Per-server Go templates

Templating is explicit and defaults off for every file. Enable **Render as a
per-server template** in the file editor or pass `is_template: true` through
the application's file-storage Create/Update API. Apply the additive database
migration through the normal Coolify upgrade workflow before enabling this UI.

Only managed regular files on supported Dockerfile/Docker Image apps are
eligible. Host files, directories, Git-managed files, services and databases
cannot opt in. Disabling Fabric fails closed for templates: no literal SSH
fallback. Ordinary files containing Go-template delimiters remain literal.

Fabric owns rendering and receives a JSON context with:

- `node`: current destination's string id, uuid, name, ip and public metadata.
- `nodes`: all unique application destinations, including the current node.
  No peer, region or role selection is performed by Coolify.
- `env`: a list of `{"key":"NAME","value":"resolved value"}` for the application's
  explicit runtime variables, resolved for this destination. Build-only,
  preview and buildpack-control variables are excluded as in normal runtime
  deployment. Values are not dotenv/shell escaped and are never re-interpreted
  as Go templates.
- `application`: application uuid and name.

The agent never reads its own process environment for template values.
Runtime secrets are sent only to their destination, not embedded in the peer
node list. Render receipts, logs and errors omit environment values and output.
Node `ip` is the configured Coolify SSH address (possibly a hostname), not an
automatically discovered IPv4 address. Optional public per-node data such as
region or advertised IP can be supplied as `metadata` alongside the existing
endpoint/token entry in FABRIC_NODES_JSON. Do not put secrets in public metadata.

For example, let the template choose a port from the supplied env list and
exclude itself from the complete node list:

```gotemplate
active-replica yes
multi-master yes
{{- $port := "6379" }}
{{- range .env }}{{ if eq .key "REDIS_PORT" }}{{ $port = .value }}{{ end }}{{ end }}
{{ range .nodes }}{{ if ne .id $.node.id }}
replicaof {{ .ip }} {{ $port }}
{{ end }}{{ end }}
```

Go syntax and the bounded `go-template/v1` contract are implemented by Fabric,
not by PHP. This is not Jinja and does not include the full Helm/Sprig function
library. Missing fields, invalid templates and resource limits fail preflight.
Fabric agent versions without /v1/configurations/render are rejected.

Save first persists the encrypted template source, then preflights every node.
If any node fails, no node receives an Apply. Otherwise each Apply re-renders
the exact same source/context and checks the preflight SHA256 before writing.
This is not a distributed filesystem transaction: subsequent network or
filesystem failures can still partially apply. Save again to retry.

Deployment also refreshes templated files after repository copying and before
container creation, even when repository preservation is disabled. Preview
deployments with templates are explicitly rejected before copying files; they
need a separate preview-path design. Load from server is disabled for templates
so one node's rendered file cannot replace the source. Disable template mode
before converting a file into a directory.

Existing bind mounts still need container recreation to observe atomic inode
replacement. Save neither deploys nor reloads the application. There is no
automatic takeover of Ansible-owned files: review ownership and rendered
configuration before enabling template mode on existing production mounts.

## Existing file ownership handover

Application storage PATCH accepts is_host_file=true to convert an existing
managed regular-file record into a bind-only reference. The source path is not
written, deleted or changed; stored content is cleared. Directories, Git files,
active template mode, content payloads, read-only mounts and reverse conversion
are rejected. Normal application admin/team authorization still applies.

This is useful when a migration app shares a configuration file owned by its
main application. Only the main application should render/save that file.

Managed-file Create also accepts an optional fs_path within the application's
configuration directory. This permits a generated file inside an existing
directory mount to retain its current host path even when it differs from the
container destination. The application root itself and paths outside it are
rejected. Omitting fs_path retains the existing destination-derived behavior.