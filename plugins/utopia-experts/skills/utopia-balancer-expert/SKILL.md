---
name: utopia-balancer-expert
description: Expert reference for utopia-php/balancer — framework-agnostic client-side load balancer with Random/First/Last/RoundRobin algorithms, chained filters, and OTel-instrumented Group failover. Consult when picking executor nodes or wiring storage-adapter failover.
---

# utopia-php/balancer Expert

## Purpose
Framework-agnostic client-side load balancer over an arbitrary set of `Option` state bags, with pluggable selection algorithms (Random/First/Last/RoundRobin), chained filter predicates, and OpenTelemetry-instrumented `Group` wrapper for fallback across multiple balancers.

## Public API
- `Utopia\Balancer\Balancer` — constructor `(Algorithm $algo)`; `addOption()`, `addFilter()`, `run(): ?Option`
- `Utopia\Balancer\Option` — opaque `array<string, mixed>` state bag with `getState/setState/deleteState/getStates`
- `Utopia\Balancer\Algorithm` (abstract) with implementations `Random`, `First`, `Last`, `RoundRobin`
- `Utopia\Balancer\Group` — chains multiple balancers, tries each until one returns a non-null option; records `balancer.run.duration` histogram via `utopia-php/telemetry`

## Core patterns
- **Filters applied before algorithm selection** — `run()` runs `array_filter` with each filter sequentially, then calls `$algo->run(array_values($filtered))` on the re-indexed result. Stateless filter predicates are the primary health-check mechanism
- **`Option` is intentionally schemaless** — whatever keys you stuff in, you read out. No validation. Use filters to enforce invariants (`online === true`, `load < threshold`)
- **`RoundRobin` is stateful** — carries `$index` across calls. Persist the algorithm instance (not the balancer) between requests or you reset every time. Constructor takes `lastIndex` as seed (typically `-1` so first call returns index 0)
- **`Group` provides priority failover semantics**, not load distribution — the first balancer that returns a non-null option wins. Model primary+fallback pools by ordering
- **Telemetry is opt-in** via `setTelemetry()`; defaults to `NoTelemetry` (no-op) so zero cost if unused

## Gotchas
- **`RoundRobin`'s `$index` is process-local** — under Swoole with multiple workers, each worker has its own counter, so distribution is uneven unless you pin clients to workers. Cross-process round-robin requires external state (Redis INCR)
- **`Balancer::run()` returns `null`** when all options are filtered out — callers must handle null before accessing `getState()`. Easy to miss in happy-path code
- **`Group` uses the first non-null**; if your primary pool has zero healthy options, fallback runs — but the histogram only records total wall time, not per-balancer. Debugging "which pool was actually hit" requires manual instrumentation
- Requires `utopia-php/telemetry: 0.1.*` (exact version pin) — upgrading telemetry forces a balancer upgrade

## Appwrite leverage opportunities
- **Executor / Functions runtime picks between builder nodes**: wrap each executor node as an `Option` with `hostname`, `cpu`, `memory`, `activeBuilds` state; use a filter for `activeBuilds < MAX` and `RoundRobin` (or a custom least-connections algorithm) for selection. Refresh state from Redis every N seconds
- **Storage adapter failover across S3-compatible providers** (primary Wasabi, fallback Backblaze): wrap as two `Balancer`s in a `Group`. Filter on `getState('healthy', true)` and flip the flag when health checks fail
- **Build a custom `LeastConnections` algorithm** extending `Algorithm` — the interface is trivial (`run(array $options): ?Option`). Would fit Appwrite realtime pod selection
- **The `balancer.run.duration` histogram** exports to OTel — pipe to Grafana/Tempo and alert when selection time spikes (usually means filters are doing too much work)

## Example
```php
use Utopia\Balancer\Algorithm\RoundRobin;
use Utopia\Balancer\Balancer;
use Utopia\Balancer\Group;
use Utopia\Balancer\Option;

$primary = new Balancer(new RoundRobin(-1));
$primary->addFilter(fn (Option $option) => $option->getState('online') && $option->getState('load', 1.0) < 0.8);
foreach ($executors as $executor) {
    $primary->addOption(new Option([
        'hostname' => $executor['host'], 'online' => $executor['healthy'], 'load' => $executor['load'],
    ]));
}

$fallback = new Balancer(new RoundRobin(-1));
$fallback->addOption(new Option(['hostname' => 'exec-fallback.internal', 'online' => true, 'load' => 0.0]));

$group = (new Group('executor-pool'))->add($primary)->add($fallback);
$picked = $group->run();
if ($picked === null) {
    throw new RuntimeException('no executor available');
}
$endpoint = $picked->getState('hostname');
```
