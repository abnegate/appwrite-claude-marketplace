---
name: utopia-lock-expert
description: Expert reference for utopia-php/lock — one Lock interface, four primitives (Mutex, Semaphore, File, Distributed) for serialising work across coroutines, processes, hosts, and clusters. Consult for cron job de-dup, Swoole coroutine throttling, distributed lease design, and choosing the right lock scope.
---

# utopia-php/lock Expert

## Purpose
Single `Lock` interface backed by four lock primitives that span every coordination scope in an Appwrite-stack service: in-worker coroutines (`Mutex`/`Semaphore`), single-host cross-process (`File`), and cross-host clustered (`Distributed`). One `withLock(callable, timeout)` API everywhere.

## Public API
- `Utopia\Lock\Lock` interface — `acquire(float $timeout = 0.0): bool`, `tryAcquire(): bool`, `release(): void`, `withLock(callable, float $timeout = 0.0): mixed`
- `Utopia\Lock\Mutex()` — Swoole `Coroutine\Channel(1)`-backed; per-worker, coroutine-scoped
- `Utopia\Lock\Semaphore(int $permits)` — Swoole `Coroutine\Channel($permits)`-backed; caps concurrent coroutines
- `Utopia\Lock\File(string $path, int $operation = LOCK_EX)` — `flock()`-backed; cross-process on shared FS. `LOCK_SH` for reader mode
- `Utopia\Lock\Distributed(\Redis $redis, string $key, int $ttl = 30)` — Redis `SET NX EX` + Lua release; cross-host. `setLogger(callable)` for retry-trace logging
- `Utopia\Lock\Exception` — base; `Utopia\Lock\Exception\Contention` thrown by `withLock` on timeout

## Patterns
- **Pick scope, not technology** — the four primitives map to `coroutine | process | host | cluster`. If your contention is between Swoole coroutines in one worker, `Mutex` is N orders of magnitude cheaper than `Distributed`
- **`withLock` is the idiom** — `acquire`/`release` exists for advanced cases but `withLock` guarantees release on exception via `finally`; on timeout it raises `Contention` so the caller never gets a half-state
- **Permit counting via `Semaphore`** — bound concurrent outbound HTTP calls or expensive computations: `new Semaphore(permits: 10)` then `withLock(fn() => $client->get(...))` — at most 10 coroutines past the gate
- **`Distributed` uses an instance token** — release verifies the value still matches before deleting, so an expired-and-re-acquired-elsewhere lock cannot be wrongly released by the original holder. TTL bounds blast radius if the holder dies
- **`File` shared mode (`LOCK_SH`)** — multiple concurrent readers, exclusive single writer. Pass `LOCK_SH` for cache-read coordination, default `LOCK_EX` for cron guards

## Gotchas
- **`Mutex`/`Semaphore` REQUIRE Swoole ≥ 6.0** and run inside a coroutine — call from non-coroutine context (CLI, FPM) and the channel ops yield to a non-existent scheduler. Use `File` or `Distributed` outside Swoole
- **`File` lock is per-inode on local FS, per-host on NFS** — `flock()` semantics over NFS are filesystem-dependent; don't assume cross-host coordination from a shared mount
- **`Distributed` needs a sane TTL** — too short and a slow holder loses its lock mid-work (and another worker enters); too long and a crashed holder blocks the whole cluster until expiry. Match TTL to P99 work duration, not the average
- **`acquire(timeout: 0.0)` is non-blocking** — equivalent to `tryAcquire()`. Pass a positive timeout for blocking semantics; `withLock` defaults to 0.0 and will throw `Contention` immediately if anyone holds the lock
- **No re-entrancy** — these are not RAII recursive locks. A coroutine that holds a `Mutex` and calls a function that re-acquires it deadlocks until timeout
- **PHP 8.3+ required** — older runtimes will composer-resolve but type-error on enum/readonly usage

## Composition
- **Cron de-dup** — wrap `utopia-queue-expert` worker entrypoints in `File('/var/run/<job>.lock', LOCK_EX)` `tryAcquire()` so a duplicate dispatch noop's instead of double-running
- **Outbound rate-shaping** — pair `Semaphore` with `utopia-fetch-expert` calls inside Swoole coroutines to cap outbound concurrency; `utopia-circuit-breaker-expert` handles failures, `Semaphore` handles concurrency
- **Cluster-wide leadership** — single-leader work (index rebuilds, schema migration) wraps the work body in `Distributed(redis, key: 'leader:reindex', ttl: 600)`; the non-leader worker hits `Contention` and skips
- **Pool resource serialisation** — when a `utopia-pools-expert` resource needs single-borrow semantics across cluster nodes, layer `Distributed` over `pop()`/`push()` keyed by the resource ID

## Example
```php
use Utopia\Lock\{Distributed, File, Mutex, Semaphore};
use Utopia\Lock\Exception\Contention;
use function Swoole\Coroutine\run;

// Coroutine-scoped fan-in to a single resource
$mutex = new Mutex();
run(function () use ($mutex) {
    foreach (range(1, 16) as $i) {
        \Swoole\Coroutine::create(function () use ($mutex, $i) {
            $mutex->withLock(fn() => writeAuditLine($i), timeout: 5.0);
        });
    }
});

// Cluster-wide single-leader migration
$redis = new \Redis();
$redis->connect('redis', 6379);

$lock = new Distributed($redis, key: 'jobs:rebuild-search-index', ttl: 600);

try {
    $lock->withLock(fn() => rebuildSearchIndex(), timeout: 30.0);
} catch (Contention) {
    // another worker is leading this run — skip cleanly
}

// Cron guard on a single host
if (! (new File('/var/run/daily-cleanup.lock'))->tryAcquire()) {
    exit("already running\n");
}
```
