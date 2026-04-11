---
name: utopia-pools-expert
description: Expert reference for utopia-php/pools — generic Pool<TResource> with reclaim, retry/reconnect, OpenTelemetry gauges, and Swoole Channel backend. Consult for sizing heuristics, leak hunting, and the Group::use() scoped borrow idiom.
---

# utopia-php/pools Expert

## Purpose
Generic connection-pool primitive (`Pool<TResource>`) with reclaim, retry/reconnect, OpenTelemetry instrumentation, and Swoole Channel backend — used everywhere Appwrite needs long-lived PDO/Redis/Mongo resources across coroutines.

## Public API
- `Utopia\Pools\Pool<TResource>` — `pop()`, `push(Connection)`, `reclaim()`, `count()`, `isFull()`, `isEmpty()`, `setReconnectAttempts/Sleep`, `setRetryAttempts/Sleep`, `setTelemetry(Telemetry)`
- `Utopia\Pools\Connection<TResource>` — `getID`, `getResource`, `setPool` — wrapper returned from `Pool::pop`; stores the back-reference so `push()` can route it back
- `Utopia\Pools\Group` — named registry: `add(Pool)`, `get(name)`, `remove(name)`, `reclaim()`, `use(array $names, callable $callback)` — scoped borrow that guarantees `push` in `finally`
- `Utopia\Pools\Adapter` — abstract; `Adapter\Swoole` (Coroutine `Channel` + `Lock`) and `Adapter\Stack` (plain array, non-Swoole)
- Telemetry gauges/histograms: `open/active/idle/capacity` connections, `waitDuration`, `useDuration` — all `NoTelemetry` by default

## Core patterns
- **Lazy init** — the adapter starts empty; connections are created on first `pop()` up to `size`, never pre-warmed. `connectionsCreated` counts lifetime creations for leak detection
- **Reconnect vs retry** — `reconnect*` handles exceptions thrown by the **init callback** (DB handshake failure). `retry*` handles an **empty pool** (pop timeout). Defaults: 3 attempts / 1 second each
- **`Group::use(['db', 'cache'], fn($pdo, $redis) => ...)`** is the idiomatic entry-point — pops each named pool, invokes with resources as positional args, pushes back on success **or** exception. Never call `pop` directly in request handlers
- Every `pop`/`push` updates telemetry; wire one `Utopia\Telemetry\Adapter\OpenTelemetry` instance and every pool in the `Group` reports
- `synchronizedTimeout = 3` seconds bounds the Swoole lock — longer critical sections hang the worker

## Gotchas
- `Pool::push` on a `Connection` whose `pool` was never set via `pop` silently discards it — always use the `Connection` returned from `pop`, never construct one by hand
- `reclaim()` pushes **all** active connections back regardless of state — if an exception killed the underlying PDO, the dead handle goes back in the pool. Wrap init in health-check logic that re-throws, triggering `reconnectAttempts`
- `Swoole` adapter's `Channel::pop(timeout)` blocks the whole coroutine; in non-coroutine context (CLI tasks, migrations) you must use `Adapter\Stack` or the worker deadlocks
- `size` is the **hard cap** on concurrent borrows. Under-sizing causes `retryAttempts` exhaustion → `Exception('Connection timeout')`; over-sizing starves the DB server. Rule of thumb: `size = max_concurrent_requests / worker_num`

## Appwrite leverage opportunities
- **Per-project sharded pools**: Appwrite Cloud routes each project to a shard but shares one `Group` — `Group::get("db_$projectShardId")` works today, but there's no LRU eviction, so the `$pools` array grows unbounded with project count. Add `Group::evictLru(int $max)` and call it on `Server::onWorkerStart`
- **Pool sizing heuristic**: Appwrite hardcodes `_APP_DB_POOL_SIZE=64`. Expose the built-in `waitDuration` histogram via `/v1/health/db` so ops can derive optimal size from P99 wait time (target < 5ms → size is right, > 50ms → double)
- **Test double**: no in-memory/fake `Adapter` for unit tests — every test touching pools spins a real Swoole channel. Ship `Adapter\Fake` that deterministically yields pre-seeded resources so `Tests\Unit` can run outside Docker
- **Reclaim on coroutine exit**: Swoole's `Coroutine::defer` is not wired — if a handler throws without `Group::use`, connections leak until the next `reclaim()`. Add `Group::useWithDefer` that registers a `Coroutine::defer` to force `push` even on fatal errors

## Example
```php
use PDO;
use Utopia\Pools\{Pool, Group};
use Utopia\Pools\Adapter\Swoole as SwooleAdapter;

$dbPool = new Pool(new SwooleAdapter(), 'db', 16, fn () => new PDO(
    'mysql:host=mariadb;dbname=appwrite;charset=utf8mb4',
    'root',
    'password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_PERSISTENT => true],
));
$dbPool->setReconnectAttempts(5)->setRetryAttempts(3);

$group = (new Group())->add($dbPool);

$rows = $group->use(['db'], function (PDO $pdo): array {
    return $pdo->query('SELECT id, name FROM _project_abc._teams LIMIT 10')->fetchAll();
});
```
