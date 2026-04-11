---
name: utopia-registry-expert
description: Expert reference for utopia-php/registry — dependency-free lazy DI container with named contexts for isolation. Consult when managing per-coroutine service lifetimes or debugging Swoole cross-request bleed.
---

# utopia-php/registry Expert

## Purpose
Dependency-free lazy DI container storing factory callables and resolved instances, with named contexts for isolation.

## Public API
- `Utopia\Registry\Registry`
- `set(string $name, callable $callback, bool $fresh = false): self`
- `get(string $name, bool $fresh = false): mixed`
- `has(string $name): bool`
- `context(string $name): self`
- Protected `$callbacks`, `$registry['context' => []]`, `$fresh`, `$context`

## Core patterns
- **Lazy factory** — callback runs only on first `get()`, result cached in `$registry[$context][$name]`
- **Context scoping** — `context('worker-1')` swaps an isolated instance map; same callbacks shared
- **Fresh mode** — `set(..., fresh: true)` or `get(..., fresh: true)` rebuilds each call (e.g. `microtime`, request-scoped resources)
- **Mutable** — `set()` after the fact overwrites the cached instance by `unset`ing it
- **No type info / no PSR-11** — caller holds the interface contract

## Gotchas
- **Swoole coroutine sharing**: a single `Registry` shared across coroutines will leak PDOs/Redis between requests. Appwrite's pattern is one `Registry` per worker + per-request `context()` swap, but you must remember to swap back
- **No thread-local/coroutine-local context** — `context()` is a single string; concurrent coroutines mutating it clobber each other
- `$fresh[$name]` is not unset when the key is `set()` again in a different context — bleed risk
- Throws generic `Exception` (not a typed `NotFoundException`) — can't distinguish "never registered" from callback failures

## Appwrite leverage opportunities
- **Per-coroutine context keys** in Swoole: derive `context()` name from `Co::getCid()` to get lock-free isolation of DB/Redis handles — avoids the cross-coroutine bleed that bit Appwrite historically
- **Fresh=true for request-scoped `Authorization`/`Document` scopes**: anything carrying user state should be `fresh`, anything carrying sockets (`$dbPool`, `$cache`) should be memoised per context
- **Registry-as-resource-pool gateway**: back it with `utopia-php/pools` so `get('db')` pulls from pool and `context()` gates checkout/return lifetime
- **Healthcheck hooks**: wrap factories so the registry becomes observability-aware (emit stats to `telemetry` on creation latency)

## Example
```php
use Utopia\Registry\Registry;

$register = new Registry();
$register->set('db', fn () => new PDO($dsn, $user, $pass));
$register->set('requestId', fn () => bin2hex(random_bytes(8)), fresh: true);

$register->context('worker-'.Co::getCid());
$pdo = $register->get('db');              // built once per coroutine
$id  = $register->get('requestId');       // new value every call
```
