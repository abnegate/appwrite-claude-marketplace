---
name: utopia-lock-expert
description: Expert reference for utopia-php/lock — one Lock interface, four backends (Mutex, Semaphore, File, Distributed). Coroutine channels for in-process; flock for cross-process; Redis SET-NX-EX with Lua release for cross-host. Consult when serialising work, capping concurrency pools, or coordinating cron/job leases across an Appwrite cluster.
---

# utopia-php/lock Expert

## Purpose
Single `Utopia\Lock\Lock` interface in front of four primitives — coroutine mutex, coroutine semaphore, OS file lock, and Redis-backed distributed lock — so callers stay backend-agnostic. `withLock()` is the workhorse (acquire-run-release with guaranteed cleanup); raw `acquire`/`tryAcquire`/`release` is escape-hatch only.

## Public API
- `Utopia\Lock\Lock` — interface: `acquire(timeout=0.0)`, `tryAcquire()`, `release()`, `withLock(callable, timeout=0.0): mixed`
- `Utopia\Lock\Mutex()` — coroutine-scoped, backed by `Swoole\Coroutine\Channel(1)`; falls back to a `$syncHeld` bool outside coroutine
- `Utopia\Lock\Semaphore(int $permits)` — backed by `Swoole\Coroutine\Channel($permits)`; throws `InvalidArgumentException` if `permits < 1`
- `Utopia\Lock\File(string $path, int $mode = LOCK_EX)` — `flock()` wrapper; supports `LOCK_SH` for shared/reader mode; opens the file lazily on first `acquire`
- `Utopia\Lock\Distributed(\Redis $redis, string $key, int $ttl = 600)` — `SET key token NX EX ttl`; release is a Lua script that verifies the token before `DEL`
- Exceptions: `Utopia\Lock\Exception` (base) + `Utopia\Lock\Exception\Contention` (timeout)
- Optional: `Distributed::setLogger(Closure(string): void)` for retry/logging visibility

## Core patterns
- **`withLock()` is the only correct call site** — guarantees `release()` in `finally`, throws `Contention` on timeout. Manual `acquire` + `release` is only for non-`callable` shaped flows
- **Timeout semantics** — `acquire(0.0)` is non-blocking (same as `tryAcquire`); a positive value waits up to that many seconds; **negative is wait-forever** (Distributed walks `microtime(true) < $deadline`, exponential backoff up to 1s)
- **Mutex/Semaphore detect coroutine context** — under `Swoole\Coroutine` the channel-backed path is used; outside, a sync int/bool path serialises within one PHP request. This keeps unit tests runnable without `ext-swoole`
- **`File` requires the directory to exist** — opens `fopen($path, 'c')` lazily, throws on missing parent. Pre-create `/var/run/myapp/` in the Dockerfile
- **`Distributed` token is unique per acquire** — `release()` is atomic via a Lua compare-and-delete, so a lock that expired and got re-acquired by another worker is never released by accident
- **TTL-bounded by design** — `Distributed::ttl` is the max time you can hold the lock; if your work exceeds it, the lock auto-expires and another worker can take it. Refresh script exists (`REFRESH_SCRIPT`) but is not exposed; use a shorter TTL with a watchdog if you need keepalive

## Gotchas
- **`Mutex`/`Semaphore` require Swoole >=6.0 in coroutine mode** — outside Swoole they degrade to single-process counters that don't cross processes; the API silently works but the guarantee is local-only
- **`File::release()` discards the handle** — re-`acquire` after `release` reopens the file, which is fine but burns an `fopen`. Don't loop acquire/release in a hot path
- **`Distributed` does not refresh** — long-running jobs whose duration is hard to bound should call a refresh script themselves, or the lock will silently expire mid-work. `REFRESH_SCRIPT` exists in src as a Lua heredoc but isn't yet wired through a public method
- **PHP 8.3 minimum** — `#[\Override]` and `private const string` are used. Older runtimes won't even autoload
- **Semaphore timeout=0 is "no wait"** — pushing onto a full channel with `0.0` blocks forever in Swoole; the implementation uses `0.001` for `tryAcquire` to dodge that, but if you call `acquire(0.0)` directly the behaviour is non-blocking by intent. Stick to `withLock()` to avoid surprises
- **`Contention` is a `RuntimeException` subclass** — `Exception` base is the package's own; both extend `\RuntimeException`, so a generic `catch (\Exception)` will swallow contention silently. Catch `Contention` explicitly when you want to retry

## Appwrite leverage opportunities
- **Replace ad-hoc Redis SET-NX in workers** — Functions/Builds/Migration workers each implement their own SET-NX-EX, often without Lua-safe release. One `Distributed($redis, "build:{$buildId}", ttl: 1800)` per worker with `withLock()` removes ~five duplicates and fixes the "released someone else's lock" bug class
- **`File` for cron singletons** — `app/tasks/*.php` cron entries currently rely on `flock` ad-hoc; standardise on `Utopia\Lock\File('/var/run/appwrite/cron-{name}.lock')` for one-line guards plus typed `Contention` on overlap
- **`Semaphore` to cap outbound HTTP fan-out** — Functions builds pull base images concurrently; a `Semaphore(permits: 8)` around `Storage::download` would smooth registry rate-limit pressure without adding a queue
- **Add a `Distributed::extend()` public method** — every long Appwrite worker (migration, builds) needs keepalive. Wrapping `REFRESH_SCRIPT` in a public method costs ten lines and unblocks watchdog usage

## Example
```php
use Redis;
use Swoole\Coroutine;
use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Lock\File;
use Utopia\Lock\Mutex;
use Utopia\Lock\Semaphore;

// 1) Coroutine-scoped serialisation inside one Swoole worker
$mutex = new Mutex();
Coroutine::create(fn () => $mutex->withLock(fn () => updateInMemoryCounter(), timeout: 1.0));

// 2) Bound concurrent outbound HTTP
$gate = new Semaphore(permits: 8);
$gate->withLock(fn () => $client->fetch($url));

// 3) Cron singleton across processes on one host
(new File('/var/run/appwrite/cron-rebuild-search.lock'))
    ->withLock(fn () => rebuildSearchIndex(), timeout: 0.0);

// 4) Distributed lease across the cluster
$redis = new Redis();
$redis->connect('redis', 6379);
$lease = new Distributed($redis, key: "build:{$buildId}", ttl: 1800);
try {
    $lease->withLock(fn () => runBuild($buildId), timeout: 30.0);
} catch (Contention) {
    // Another worker already owns this build — re-queue or skip
}
```
