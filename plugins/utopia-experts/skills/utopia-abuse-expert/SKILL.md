---
name: utopia-abuse-expert
description: Expert reference for utopia-php/abuse — sliding/fixed-window rate limiting with Redis/RedisCluster/Database/Appwrite/ReCaptcha adapters. Consult for login throttling, sliding-window implementations, and per-tenant dimensioned keys.
---

# utopia-php/abuse Expert

## Purpose
Sliding/fixed-window rate limiting for authentication and API abuse with pluggable storage backends.

## Public API
- `Utopia\Abuse\Abuse` — facade wrapping an adapter
- `Utopia\Abuse\Adapter` — abstract: `check`, `getLogs`, `cleanup`, `reset`, `setParam`, `parseKey`
- `Utopia\Abuse\Adapters\TimeLimit` — abstract time-windowed base, with concretions:
  - `TimeLimit\Database` — over `utopia-php/database` (MySQL/MariaDB/Postgres)
  - `TimeLimit\Redis` / `TimeLimit\RedisCluster` — TTL-scoped
  - `TimeLimit\Appwrite\TablesDB` — via Appwrite SDK (external services hitting Cloud)
- `Utopia\Abuse\Adapters\ReCaptcha` — Google ReCaptcha verification

## Core patterns
- **Key templating via `{{var}}` placeholders** resolved through `setParam('{{ip}}', $ip)` — keeps key generation colocated with the limit definition
- **`check()` is both read and write** — it counts, compares, increments, and returns `true` when abused. Always use the return value, never call `hit()` manually
- **Fixed window snapped to `floor($now / $seconds) * $seconds`** — a **fixed** window, not a true sliding window. Redis adapter uses `INCR` + `EXPIRE` via `MULTI/EXEC`
- **Schema owned by the library** (`TimeLimit::ATTRIBUTES` + `INDEXES`) and created via `$adapter->setup()`
- `limit = 0` means unlimited (short-circuits to `false`)

## Gotchas
- **Fixed window means a user can do `2 * limit` requests near the boundary.** For strict sliding behaviour, roll your own with Redis ZSET or switch to leaky bucket
- The Database adapter has a unique index on `(key, time)` — parallelising `hit()` produces `Duplicate` exceptions; the adapter swallows them but other callers must not catch-and-rethrow
- Redis adapter's `count` is cached per-request — two `check()` calls in the same request return stale data for the second
- `appwrite/appwrite: 19.*` is a hard dep of `utopia-php/abuse` (for the `TablesDB` adapter) — **circular dep risk**, pin carefully

## Appwrite leverage opportunities
- **Redis on hot paths** (login, account/create, email OTP) and **Database on low-traffic admin endpoints** to avoid bloating MySQL writes
- **Distributed limits across API nodes**: prefer `RedisCluster` over `Redis` — sharded keyspace but window snapping is identical, so counters converge without sync
- **True sliding window**: subclass `TimeLimit` and use Redis ZADD/ZRANGEBYSCORE with `now - interval` rather than fixed buckets for DDoS-sensitive endpoints
- **Dimensioned keys** via `{{ip}}:{{userAgent}}` — cheap way to add dimension to keys without composite-key gymnastics. Use for per-project-per-IP rate limits in Functions executions
- **Rate limit headers**: wrap `check()` in middleware that emits `X-RateLimit-Remaining`, `X-RateLimit-Reset` from `remaining()` + `$timestamp + $ttl` — the data is already there

## Example
```php
use Utopia\Abuse\Abuse;
use Utopia\Abuse\Adapters\TimeLimit\Redis as RedisLimit;

$redis = new \Redis();
$redis->connect('redis', 6379);

$adapter = new RedisLimit('login:{{ip}}:{{email}}', limit: 5, seconds: 300, redis: $redis);
$adapter->setParam('{{ip}}', $request->getIP());
$adapter->setParam('{{email}}', $email);

if ((new Abuse($adapter))->check()) {
    throw new Exception('Too many login attempts', 429);
}
```
