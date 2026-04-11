---
name: utopia-system-expert
description: Expert reference for utopia-php/system — zero-dependency static helper for host CPU/memory/disk/network/IO and architecture detection. Consult when wiring health endpoints, runtime tag selection, or container resource feeds to telemetry.
---

# utopia-php/system Expert

## Purpose
Zero-dependency static helper for querying host CPU/memory/disk/network/IO and architecture detection across Linux, macOS, and Windows.

## Public API
- `Utopia\System\System::getOS()` — returns `php_uname('s')` (Linux/Darwin/Windows)
- `System::getArch()` / `getArchEnum()` — raw uname `m` or normalised enum (X86, ARM64, ARMV7, ARMV8, PPC)
- `System::isArm()` / `isPPC()` / `isX86()` — boolean arch predicates
- `System::getHostname()` / `getEnv(string $key)` — host identity helpers
- `System::getCPUCores()` / `getCPUUsage(int $duration)` — core count + `/proc/stat` delta sampling
- `System::getMemoryTotal()` / `getMemoryFree()` — `/proc/meminfo` on Linux, sysctl-based on macOS
- `System::getDiskTotal()` / `getDiskFree()` — wraps `disk_total_space` / `disk_free_space`
- `System::getIOUsage(int $duration)` / `getNetworkUsage(int $duration, string $interface)` — Linux-only sampling via `/proc/diskstats` and `/proc/net/dev`

## Core patterns
- **Pure static class** — no state, no DI, safe to call from hot paths
- **Per-OS dispatch** inside each method; throws `Exception` on unsupported OS
- **Sampling methods take a `$duration`** (seconds) and compute deltas — expensive, should be run in a background coroutine
- **`INVALID_DISKS` / `INVALIDNETINTERFACES` constants** filter out `loop`, `ram`, `veth`, `docker`, `lo`, `tun`, `vboxnet` pseudo-devices
- Regex-based architecture matching so `aarch64` and `arm64` both map to `ARM64`

## Gotchas
- Most metrics beyond core count are **Linux-only** — macOS/Windows throw or return 0 for CPU usage, IO and network
- **`getCPUUsage()`, `getIOUsage()`, `getNetworkUsage()` block the caller for `$duration` seconds** (two reads with a sleep between) — never call from a request handler without coroutines
- Reads `/proc/...` via `file_get_contents` — fails silently inside chroots and some container sandboxes if `/proc` isn't mounted
- Falls back to host binaries on macOS / Windows — fails if binaries are missing or PHP `disable_functions` restricts process spawning

## Appwrite leverage opportunities
- **Runtime tag selection**: use `getArchEnum()` to pick the correct runtime tag in `appwrite/runtimes` — Appwrite already does this in `Runtimes` but custom Functions images could use the same check to avoid drift
- **Health endpoint**: `getCPUUsage()` + `getMemoryFree()` are perfect for `/v1/health/system` to replace the current Docker-stats probe; pair with Swoole coroutines so sampling doesn't block workers
- **Feed metrics into `utopia-php/telemetry` as OpenTelemetry gauges** (`system.cpu.utilisation`, `system.memory.usage`) — currently missing from the Appwrite dashboard
- **Functions executor quotas**: use `getIOUsage()` and `getNetworkUsage()` with the executor's veth pair name to enforce per-function network quotas that Docker's built-in limits can't

## Example
```php
use Utopia\System\System;

if (System::isArm()) {
    $image = 'appwrite/runtime-php:8.3-arm64';
} else {
    $image = 'appwrite/runtime-php:8.3';
}

$cpuUsage = System::getCPUUsage(1);
$memoryFree = System::getMemoryFree();
if ($cpuUsage > 80 || $memoryFree < 256 * 1024 * 1024) {
    throw new Exception('Host overloaded; refusing new function invocation');
}
```
