---
name: utopia-cache-expert
description: Expert reference for utopia-php/cache — unified TTL-aware K/V cache over Redis/Memcached/Hazelcast/filesystem/memory with Sharding and Pool composition. Consult for stampede protection, read-time TTL semantics, and adapter composition.
---

# utopia-php/cache Expert

## Purpose
Unified TTL-aware key/value cache facade over Redis, RedisCluster, Memcached, Hazelcast, filesystem, in-memory, and composite (Sharding, Pool, None) adapters, with retry semantics and telemetry.

## Public API
- `Utopia\Cache\Cache` — facade: `load($key, $ttl, $hash = '')`, `save`, `list`, `purge`, `flush`, `ping`, `getSize`; toggles `caseSensitive`
- `Utopia\Cache\Adapter` (interface) — contract plus `setMaxRetries(0-10)` / `setRetryDelay(ms)`
- `Adapter\Redis`, `Adapter\RedisCluster` — ext-redis wrappers with reconnect-on-failure
- `Adapter\Memcached`, `Adapter\Hazelcast`, `Adapter\Filesystem`, `Adapter\Memory`, `Adapter\None`
- `Adapter\Sharding` — consistent-hash fan-out across N adapters
- `Adapter\Pool` — wraps `utopia-php/pools` to pull a fresh connection per op (Swoole-friendly)

## Core patterns
- **`load($key, $ttl)` passes TTL on read** — the adapter checks stored mtime against `$ttl` and returns `false` (miss) if expired. This lets the same key be read with different freshness windows
- **Hash sub-keys**: `$cache->save('user:123', $data, $version)` stores under `key/hash`; `load('user:123', $ttl, $oldVersion)` acts as automatic invalidation — change the hash and the miss is free
- **Sharding is stateless and deterministic** — add an adapter and ~1/N of keys re-route (expect a transient hit-rate drop, not a full flush)
- **Case-insensitive keys by default** — `setCaseSensitivity(true)` if you shard by casing-preserved IDs

## Gotchas
- `save()` returns the data back on success, `false`/empty on failure — don't `if (!$cache->save(...))` on falsy data like `[]` or `'0'`, it'll misfire
- Filesystem adapter writes one file per key; `flush()` rm-rf's the directory — don't point it at a shared path
- Retry logic is per-adapter, not in the facade — `Sharding` and `Pool` fan `setMaxRetries` down, but custom composites must forward manually
- The README claims "Filesystem only" — outdated; Redis/Memcached/Hazelcast/Cluster/Sharding/Pool all exist in src

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
