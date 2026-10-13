# Fabric-backed managed file saves

This opt-in integration applies to editable, regular managed files on Dockerfile
applications (including inline Dockerfiles). Coolify remains authoritative for
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
