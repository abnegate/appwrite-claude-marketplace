---
name: utopia-circuit-breaker-expert
description: Expert reference for utopia-php/circuit-breaker — three-state breaker with Redis/Swoole-Table shared state and OpenTelemetry instrumentation. Consult when wrapping flaky downstream dependencies, sizing thresholds, sharing breaker state across workers, or interpreting `breaker.*` telemetry.
---

# utopia-php/circuit-breaker Expert

## Purpose
Three-state circuit breaker (CLOSED → OPEN → HALF_OPEN) for protecting Appwrite-stack services from cascading downstream failures. Optional shared state across workers via Redis or Swoole Table; opt-in metrics through `utopia-php/telemetry`.

## Public API
- `Utopia\CircuitBreaker\CircuitBreaker(threshold=3, timeout=30, successThreshold=2, ?Adapter $cache=null, cacheKey='default', ?Telemetry $telemetry=null, metricPrefix='')`
- `call(callable $open, callable $close, ?callable $halfOpen=null): mixed` — `$open` is the fallback, `$close` runs while CLOSED, `$halfOpen` (or `$close`) runs during recovery probing
- `getState(): CircuitState`, `isOpen()/isClosed()/isHalfOpen()`, `getFailureCount()`, `getSuccessCount()`, `setTelemetry(Telemetry)`
- `Utopia\CircuitBreaker\CircuitState` enum — `CLOSED`, `OPEN`, `HALF_OPEN`
- `Utopia\CircuitBreaker\Adapter` interface — `Adapter\Redis(\Redis $redis)`, `Adapter\SwooleTable(\Swoole\Table $table)` with static `SwooleTable::createTable(int $size)` factory
- `Utopia\CircuitBreaker\Adapter\AdapterException` — adapter-level cache failure

## Patterns
- **State machine with hysteresis** — `threshold` consecutive failures opens the circuit; `timeout` seconds later the next call probes via HALF_OPEN; `successThreshold` consecutive HALF_OPEN successes returns to CLOSED. Any HALF_OPEN failure re-opens immediately
- **Two-callback vs three-callback** — passing `$halfOpen` lets you probe with reduced load (smaller batch, shorter timeout, more logging). Without it, `$close` is reused; the breaker still gates entry, the callback just doesn't know it's a probe
- **Shared state via cache adapter** — pass `cache` + `cacheKey` to make multiple PHP workers (Swoole, FPM pool) see the same breaker. Each unique downstream gets its own `cacheKey`; reusing keys merges breakers (sometimes desirable for a fleet of mirrors)
- **Telemetry namespace** — `breaker.calls`, `breaker.fallbacks`, `breaker.callback_failures`, `breaker.transitions`, `breaker.active_calls`, `breaker.state`, `breaker.failures`, `breaker.successes`, `breaker.event.timestamp`. `metricPrefix: 'edge'` rewrites them as `edge.breaker.*`
- **Atomic state sync** — every `call()` re-reads cached state on entry and writes after; concurrent workers race-converge but don't lock. If two workers cross the threshold simultaneously, they both see OPEN on the next sync

## Gotchas
- **`$open` must always succeed** — when the circuit is OPEN, `$open` runs every call; if it throws, the breaker provides no protection. Default to a cached/empty/sentinel return rather than re-throwing
- **`successThreshold` defaults to 2 but counters reset on any failure** — flapping downstreams stay OPEN until they're stable for `successThreshold` consecutive probes; setting `successThreshold: 1` makes recovery aggressive but flap-prone
- **`Adapter\Redis` requires the `redis` extension; `Adapter\SwooleTable` requires `ext-swoole`** — neither is a hard composer dep. Unit tests use the in-memory default; integration tests need Docker
- **Telemetry attribute cardinality** — `circuit_breaker.outcome` has values `success`, `fallback`, `short_circuit`, `exception`; `exception.type` adds the FQCN of any thrown class. Don't put per-request IDs in metric attrs
- **`cacheKey` is a hash key, not a Redis key prefix** — adapter handles namespacing internally; don't try to clear with `KEYS breaker:*`

## Composition
- **External-API guards** — wrap every `utopia-fetch-expert` call to a third-party (OAuth provider, email vendor, billing) in a per-host breaker. `metricPrefix: 'outbound'` keeps dashboards clean
- **Database fallback** — pair with `utopia-pools-expert`: when the DB pool exhausts retry attempts, the breaker opens and `$open` returns a degraded read from `utopia-cache-expert`
- **Edge purge resiliency** — `utopia-cdn-expert` `purgeUrls` calls Fastly/Cloudflare; wrap in a breaker so a CDN outage doesn't block deploys
- **Worker fan-out** — `utopia-queue-expert` workers can share one breaker via `Adapter\Redis` so the whole pool stops hammering a downed service together

## Example
```php
use Utopia\CircuitBreaker\Adapter\Redis as RedisAdapter;
use Utopia\CircuitBreaker\CircuitBreaker;
use Utopia\Telemetry\Adapter\OpenTelemetry;

$redis = new \Redis();
$redis->connect('redis', 6379);

$breaker = new CircuitBreaker(
    threshold: 5,
    timeout: 60,
    successThreshold: 2,
    cache: new RedisAdapter($redis),
    cacheKey: 'oauth:github',
    telemetry: new OpenTelemetry('http://otel:4318/v1/metrics', 'appwrite', 'oauth', gethostname() ?: 'local'),
    metricPrefix: 'outbound',
);

$profile = $breaker->call(
    open: fn () => ['fallback' => true, 'login' => null],
    close: fn () => $githubClient->getProfile($accessToken),
    halfOpen: fn () => $githubClient->getProfile($accessToken, timeout: 3),
);
```
