---
name: utopia-cache-expert
description: Expert reference for utopia-php/cache — unified TTL-aware K/V cache over Redis/Memcached/Hazelcast/filesystem/memory with Sharding and Pool composition. Consult for stampede protection, read-time TTL semantics, and adapter composition.
---

# utopia-php/cache Expert

## Purpose
Unified TTL-aware key/value cache facade over Redis, RedisCluster, Memcached, Hazelcast, filesystem, in-memory, and composite (Sharding, Pool, None) adapters, with retry semantics and telemetry.

## Public API
- `Utopia\Cache\Cache` — facade: `load($key, $ttl, $hash = '')`, `save`, `touch($key, $hash = '')` (refresh mtime without rewriting), `list`, `purge`, `flush`, `ping`, `getSize`, `setTelemetry(Telemetry)`; toggles `caseSensitive`. Records the `cache.operation.duration` histogram per call with `operation` / `adapter` attributes
- `Utopia\Cache\Adapter` (interface) — `load`/`save`/`touch`/`list`/`purge`/`flush`/`ping`/`getSize`/`getName`. Retry knobs and telemetry are now opt-in capability interfaces (see below), not part of the base contract
- `Utopia\Cache\Feature\Retryable` — capability interface; `setMaxRetries(0-10)`/`setRetryDelay(ms)`/`getMaxRetries`/`getRetryDelay` for adapters that retry (`Redis`, `RedisCluster`, `Memcached`)
- `Utopia\Cache\Feature\Telemetry` — capability interface; `setTelemetry(Telemetry)`. Implemented by `Cache`, `CircuitBreaker`, and `Redis\Multiplexing`. `Cache::setTelemetry()` cascades to the inner adapter when it satisfies this interface, so wiring telemetry once on the facade reaches connection-level gauges automatically
- `Adapter\Redis`, `Adapter\RedisCluster` — ext-redis wrappers with reconnect-on-failure; reads/writes pass through `Adapter\Redis\Envelope::encode/decode` so a stored entry carries its own write-time mtime in the value, and the same TTL check works against any RESP-speaking backend
- `Adapter\Redis\Multiplexing(host, port = 6379, timeout = 1.0, readTimeout = 0.25, auth = null, dbIndex = 0)` — Swoole-only adapter that funnels every coroutine through a single Redis TCP connection. A connection-wide `Swoole\Coroutine\Lock` serialises pending enqueue + send so the FIFO invariant holds; one reader coroutine parses RESP frames and dispatches each to the next pending Channel, leveraging Redis's in-order-reply guarantee. Emits `cache.redis_multiplexing.pending.depth` gauge — a steady-state non-zero value means callers are enqueueing faster than Redis is replying. Call `disconnect()` for clean shutdown. Compared to `Pool`: one connection instead of N, lower memory and TCP overhead, but a slow Redis reply head-of-lines every waiting coroutine on that worker — size readTimeout aggressively (default 0.25s) and fall back to `Pool` if you need request isolation
- `Adapter\Memcached`, `Adapter\Hazelcast`, `Adapter\Filesystem`, `Adapter\Memory`, `Adapter\None`. `Filesystem(string $path, bool $streaming = false)` — when `$streaming = true`, `load()` returns a readable file handle (`fopen($file, 'rb')`) for cache hits instead of slurping the whole file via `file_get_contents`. Caller is responsible for `fclose()`. Useful for large blobs (built bundles, image previews) that don't need to live in PHP memory
- `Adapter\Sharding` — consistent-hash fan-out across N adapters. Does NOT itself implement `Feature\Telemetry` — sharded adapters wire their own telemetry independently
- `Adapter\Pool` — wraps `utopia-php/pools` to pull a fresh connection per op (Swoole-friendly)
- `Adapter\CircuitBreaker` — wraps any adapter with `utopia-php/circuit-breaker`; trips after threshold failures and short-circuits subsequent calls. Implements `Feature\Telemetry` and forwards the adapter to the wrapped `CircuitBreaker`

## Core patterns
- **`load($key, $ttl)` passes TTL on read** — the adapter checks stored mtime against `$ttl` and returns `false` (miss) if expired. This lets the same key be read with different freshness windows
- **Hash sub-keys**: `$cache->save('user:123', $data, $version)` stores under `key/hash`; `load('user:123', $ttl, $oldVersion)` acts as automatic invalidation — change the hash and the miss is free
- **Sharding is stateless and deterministic** — add an adapter and ~1/N of keys re-route (expect a transient hit-rate drop, not a full flush)
- **Case-insensitive keys by default** — `setCaseSensitivity(true)` if you shard by casing-preserved IDs

## Gotchas
- `save()` returns the data back on success, `false`/empty on failure — don't `if (!$cache->save(...))` on falsy data like `[]` or `'0'`, it'll misfire
- Filesystem adapter writes one file per key; `flush()` rm-rf's the directory — don't point it at a shared path
- Retry logic is per-adapter via `Feature\Retryable`, not in the facade — `Sharding` and `Pool` fan `setMaxRetries` down, but custom composites must check `instanceof Retryable` before forwarding
- **`Adapter\Redis\Multiplexing` is Swoole-only and single-connection** — head-of-line blocking. Throws `InvalidArgumentException` on non-positive `timeout`/`readTimeout`. Don't enable cluster-mode features (MOVED/ASK redirects) — the adapter doesn't follow them
- **Telemetry no longer propagates through Sharding** — if you wrap multiple adapters under `Sharding`, set telemetry on each one before constructing the sharder. The base `Cache` facade only cascades into a *single* inner adapter
- **Composer constraint is now `utopia-php/cache: ^3.0`** — bumped in lockstep across utopia-php/database, dns, domains, vcs, and appwrite/appwrite. The 2.x ↔ 3.x boundary added `touch()` to the `Adapter` contract — third-party adapters must implement it
- The README claims "Filesystem only" — outdated; Redis/Memcached/Hazelcast/Cluster/Sharding/Pool/Multiplexing/CircuitBreaker all exist in src

## Appwrite leverage opportunities
- **Cache stampede protection is absent**: two workers missing the same key will both recompute. Wrap `load()` with per-key Redis `SETNX` lock (or probabilistic early expiration — return stale to N-1 callers while one refreshes). Add a `remember($key, $ttl, callable $loader)` method
- **Sharding + Pool composition for Swoole workers**: pair `Adapter\Sharding([new Pool($poolA), new Pool($poolB)])` to get both connection reuse and horizontal split — the pattern Appwrite should document as default for Cloud
- **Per-op TTL override**: current `save()` has no TTL arg — forces write-time-eternal + read-time-ttl. For Redis that wastes memory when keys are never re-read with the same TTL; a native `saveWithTtl` using `SETEX` would let the server evict
- **Negative caching**: `load()` returning `false` is ambiguous (miss vs. stored `false`). A sentinel wrapper would let Appwrite cache 404s safely

## Example
```php
use Utopia\Cache\Cache;
use Utopia\Cache\Adapter\Sharding;
use Utopia\Cache\Adapter\Redis as RedisAdapter;

$shardA = new \Redis(); $shardA->pconnect('cache-a', 6379);
$shardB = new \Redis(); $shardB->pconnect('cache-b', 6379);

$cache = new Cache(new Sharding([
    (new RedisAdapter($shardA))->setMaxRetries(3)->setRetryDelay(50),
    (new RedisAdapter($shardB))->setMaxRetries(3)->setRetryDelay(50),
]));

$key = 'project:' . $projectId . ':collections';
$version = $project->getAttribute('updatedAt');

$data = $cache->load($key, 3600, $version);
if ($data === false) {
    $data = $database->find('collections', [...]);
    $cache->save($key, $data, $version);
}
```
