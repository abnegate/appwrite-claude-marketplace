---
name: utopia-circuit-breaker-expert
description: Expert reference for utopia-php/circuit-breaker — three-state breaker (CLOSED/OPEN/HALF_OPEN) protecting calls to misbehaving downstream deps, with optional shared state via Redis or Swoole\Table and OpenTelemetry counters/gauges/up-down counters via utopia-php/telemetry. Consult when wrapping flaky integrations, sizing thresholds, or wiring shared state across Swoole workers.
---

# utopia-php/circuit-breaker Expert

## Purpose
Three-state circuit breaker (`CLOSED`/`OPEN`/`HALF_OPEN`) for short-circuiting calls to flaky downstream dependencies. State lives in-process by default but can be shared across workers via Redis or `Swoole\Table` adapters. Telemetry is opt-in via `utopia-php/telemetry`.

## Public API
- `Utopia\CircuitBreaker\CircuitBreaker(threshold=3, timeout=30, successThreshold=2, ?Adapter $cache, cacheKey='default', ?Telemetry, metricPrefix='')` — `call(callable $open, callable $close, ?callable $halfOpen)`, `setTelemetry(Telemetry)`, `trip()` (force OPEN), `getState()`, `getFailureCount()`, `getSuccessCount()`, `isOpen()/isClosed()/isHalfOpen()`
- `Utopia\CircuitBreaker\CircuitState` — enum: `CLOSED|OPEN|HALF_OPEN`
- `Utopia\CircuitBreaker\Adapter` — interface: `get(key)`, `set(key, value)`, `increment(key, by=1)`, `delete(key)`
- `Utopia\CircuitBreaker\Adapter\Redis(object $redis, prefix='breaker:')` — duck-typed; wraps any client exposing `get/set/incrBy/del`
- `Utopia\CircuitBreaker\Adapter\SwooleTable(object $table, prefix='breaker:')` plus `SwooleTable::createTable(size=1024, valueLength=255)` factory
- `Utopia\CircuitBreaker\Adapter\AdapterException` — single typed failure surface for cache I/O errors

## Core patterns
- **Three states** — CLOSED counts failures up to `threshold`; OPEN short-circuits to `$open` for `timeout` seconds; HALF_OPEN runs `$halfOpen` (or `$close` if absent) and needs `successThreshold` consecutive successes to close again. Any failure in HALF_OPEN re-opens immediately
- **`call()` always returns the callback result** — `$open` is your fallback, not an error path. Throw inside `$open` if you want failure to propagate; otherwise return cached/default data
- **`$halfOpen` is for shrunken probes** — shorter timeout, smaller payload, extra logging. Defaults to `$close` so the recovery path matches normal traffic if you don't care
- **State is read on every `call()`** via `syncFromCache()` — the in-memory mirror is invalidated each invocation, so distributed breakers never disagree by more than one call
- **Telemetry is opt-in** — passing no `telemetry` adapter emits zero metrics and incurs no `utopia-php/telemetry` runtime requirement. With one, you get `breaker.calls`, `breaker.fallbacks`, `breaker.transitions`, `breaker.active_calls`, `breaker.state`/`failures`/`successes` gauges, `breaker.event.timestamp`
- **`metricPrefix`** namespaces all metrics — `metricPrefix: 'edge'` produces `edge.breaker.calls`, etc. Set per host service so dashboards can split

## Gotchas
- **In-memory only by default** — without an `Adapter`, two PHP-FPM workers see independent breakers. Production multi-worker setups must pass `Adapter\Redis` or `Adapter\SwooleTable` plus a stable `cacheKey`
- **`cacheKey` must be unique per protected dependency** — sharing one key across calls means a failure in service A trips the breaker for service B. Use `users-api`, `email-provider`, etc.
- **Redis adapter is duck-typed**, not strictly typed — it accepts any object with `get/set/incrBy/del`. `phpredis` and `Predis` both work; cluster clients also work but be careful that `incrBy` hashtags `cacheKey` to one slot
- **`SwooleTable::createTable()` requires `ext-swoole`** at create-time; running it under FPM throws. The adapter itself is duck-typed too — you can swap a fake table in tests
- **Half-open probes can stampede** — without rate limiting, every worker hitting HALF_OPEN at once will flood the recovering service. Prefer `successThreshold: 1` with low traffic, higher with high
- **No tripping by latency** — only thrown exceptions count as failures. A 30-second timeout is "success" unless your `$close` throws on slow responses
- **`trip()` forces OPEN out-of-band** — idempotent (re-tripping refreshes `openedAt` and re-emits gauges, no extra `transitions` recorded). Use it from a circuit-management endpoint or admin task to take a flapping dependency offline manually; the breaker still self-heals after `timeout` via the normal HALF_OPEN probe

## Appwrite leverage opportunities
- **Wrap every external integration** in Functions/Messaging/OAuth providers with one breaker per provider — currently a single misbehaving SMTP provider can saturate the messaging worker pool. `cacheKey: "email-{provider}"` plus `Adapter\Redis` shares trip state across workers
- **Dashboard the `breaker.transitions` counter** — if a breaker is flapping (>5 transitions/min), it's threshold-mistuned. Pair with the existing OpenTelemetry exporter and add an alert
- **`Adapter\SwooleTable` for the orchestrator runtime** — orchestrator is single-host Swoole; shared-memory breakers have zero network cost vs Redis. One `SwooleTable::createTable(size: 64)` covers all downstream containers
- **Add a `breaker:list` CLI command** to print state of every cacheKey for ops triage — currently you have to redis-cli and parse the prefix manually

## Example
```php
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\CircuitBreaker\Adapter\Redis as BreakerRedis;
use Utopia\Telemetry\Adapter\OpenTelemetry;

$redis = new \Redis();
$redis->connect('redis', 6379);

$breaker = new CircuitBreaker(
    threshold: 5,
    timeout: 60,
    successThreshold: 2,
    cache: new BreakerRedis($redis),
    cacheKey: "smtp-{$providerId}",
    telemetry: new OpenTelemetry('http://otel:4318/v1/metrics', 'messaging', 'workers', \gethostname() ?: 'local'),
    metricPrefix: 'messaging',
);

$result = $breaker->call(
    open: fn () => ['queued_for_retry' => true],
    close: fn () => $smtpClient->send($message),
    halfOpen: fn () => $smtpClient->send($message, timeout: 5),
);
```
