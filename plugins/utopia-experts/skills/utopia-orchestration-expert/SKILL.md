---
name: utopia-orchestration-expert
description: Expert reference for utopia-php/orchestration — thin abstraction over Docker socket API and Docker CLI. Consult when working on the Appwrite Functions executor, debugging container lifecycle, or planning a Kubernetes adapter.
---

# utopia-php/orchestration Expert

## Purpose
Thin abstraction over container runtimes (Docker socket API and Docker CLI) for pulling images, running/executing/removing containers and managing networks — the engine behind Appwrite Functions.

## Public API
- `Utopia\Orchestration\Orchestration` — facade delegating every call to an `Adapter`
- `Utopia\Orchestration\Adapter` — abstract base with `RESTART_NO|ALWAYS|ON_FAILURE|UNLESS_STOPPED` constants and `namespace/cpus/memory/swap` caps
- `Adapter\DockerAPI` — talks directly to `/var/run/docker.sock` via cURL `CURLOPT_UNIX_SOCKET_PATH`; supports streaming exec
- `Adapter\DockerCLI` — shells out to the `docker` binary; useful when the socket isn't mountable
- `Container` — value object (id, name, labels, status) returned by `list()`
- `Container\Stats` — per-container CPU/memory/net/IO snapshot from `getStats()`
- `Network` — value object for `listNetworks()`
- `Exception\{Orchestration, Timeout}` — typed failures distinguishing API errors from `execute()` deadline

## Core patterns
- **Facade + adapter**: `Orchestration` is a 1:1 passthrough; all logic lives in adapters, so swapping backends is a constructor change
- **`DockerAPI` uses cURL over unix socket** with a fake `Host: utopia-php` header (works around a Swoole header bug with UDS)
- **`execute()` is pass-by-reference** for `$output` and accepts a `$timeout` — implemented as streamed attach in `DockerAPI::streamCall`
- **`parseCommandString()`** handles single-quoted argument groups so `sh -c 'echo hi'` stays intact
- **Registry auth** is base64-encoded JSON passed as `X-Registry-Auth` header during pull (DockerAPI) or via `docker login` (DockerCLI)

## Gotchas
- **No Kubernetes, Podman, containerd, Nomad, or Swarm adapter** despite the composer keywords — only Docker
- `DockerAPI` requires read/write on `/var/run/docker.sock`, which is effectively root — a well-known attack surface for the Appwrite executor
- `setCpus/setMemory/setSwap` are instance-level defaults on the adapter and only apply if the adapter implementation honours them — DockerCLI sets `--cpus` flag, DockerAPI sends `NanoCPUs` in create body, but they diverge on `PidsLimit`
- `execute()` output is a single concatenated string (not split stdout/stderr in current API); `remove(force: false)` silently fails on still-running containers
- Adapter methods return `bool` on success but throw `Exception\Orchestration` on interesting failures — callers must try/catch, not check return

## Appwrite leverage opportunities
- **Add a Kubernetes adapter** that maps `run()` to a `Job` CRD and `execute()` to `kubectl exec` — Cloud currently runs a vanilla Docker executor on each VM, blocking horizontal scaling; a K8s adapter unlocks namespace-per-project isolation, ResourceQuotas, and NetworkPolicies that Docker `--cpus/--memory` can't express
- **Expose `ReadOnlyRootFilesystem`, `CapDrop`, `SeccompProfile`, `Userns=keep-id`** in the adapter surface — Appwrite executor currently hand-writes these in raw Docker API calls, bypassing the abstraction
- **Cache pulled image digests** in Swoole Table keyed by `image:tag@digest` so the executor skips `pull()` when the digest is already local — today it re-checks on every cold start
- **Plug `getStats()` into `utopia-php/telemetry`** as per-function histograms so Cloud billing stops relying on the separate `runtime-stats` sidecar
- **Reuse the Unix-socket cURL pattern with HTTP/2 multiplexing** (`CURLMOPT_PIPELINING`) to make parallel `execute()` calls share one connection

## Example
```php
use Utopia\Orchestration\Orchestration;
use Utopia\Orchestration\Adapter\DockerAPI;
use Utopia\Orchestration\Adapter;

$orchestration = new Orchestration(new DockerAPI());
$orchestration->setNamespace('appwrite-fx')->setCpus(1.0)->setMemory(256);

$orchestration->pull('appwrite/runtime-node:20');
$id = $orchestration->run(
    image: 'appwrite/runtime-node:20',
    name: 'fn-' . bin2hex(random_bytes(8)),
    command: ['tail', '-f', '/dev/null'],
    labels: ['appwrite-type' => 'function'],
    restart: Adapter::RESTART_NO,
);
$output = '';
$orchestration->execute($id, ['node', '/usr/code/index.js'], $output, [], timeout: 15);
$orchestration->remove($id, force: true);
```
