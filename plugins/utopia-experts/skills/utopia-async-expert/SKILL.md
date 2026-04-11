---
name: utopia-async-expert
description: Expert reference for utopia-php/async — Promises/A+ concurrency and true multi-core Parallel execution for PHP 8.1+, auto-selecting Swoole/React/Amp/Parallel/Sync. Consult for concurrent I/O, CPU-bound fanout, and timeout patterns.
---

# utopia-php/async Expert

## Purpose
Promises/A+ concurrency plus true multi-core parallel execution for PHP 8.1+, auto-selecting the best runtime adapter (Swoole/React/Amp/ext-parallel/Sync).

## Public API
- `Utopia\Async\Promise` — static facade: `async()`, `run()`, `all()`, `map()`, `race()`, `any()`, `allSettled()`, `resolve()`, `reject()`, `delay()`, `setAdapter()`
- `Utopia\Async\Parallel` — static facade: `run()`, `all()`, `map()`, `forEach()`, `pool()`, `createPool()`, `shutdown()`
- `Utopia\Async\Promise\Adapter` — base: `then()`, `catch()`, `finally()`, `await()`, `timeout()`
- `Promise\Adapter\{Swoole\Coroutine, React, Amp, Sync}`
- `Parallel\Adapter\{Swoole\Thread, Swoole\Process, Parallel, React, Amp, Sync}`
- `Utopia\Async\Serializer` — closure serialization via `opis/closure` for cross-process execution
- `Utopia\Async\{Timer, GarbageCollection, Exception\Adapter}`

## Core patterns
- **Dual-facade design**: `Promise` for single-threaded concurrency (coroutines/event loop), `Parallel` for multi-core CPU work — pick by workload
- **Auto-detection ladder**: `Promise::detectAdapter()` walks `Coroutine → React → Amp → Sync`; `Parallel::detectAdapter()` walks `SwooleThread → ext-parallel → SwooleProcess → React → Amp → Sync`
- **Promises/A+ semantics** with `then/catch/finally/await`; `timeout(ms)` converts to rejection on expiry
- **`Parallel::pool($tasks, $concurrency)`** gives bounded concurrency without manually managing workers; default pool auto-cleanup via `register_shutdown_function`
- **Closures serialized with `opis/closure`** for process-boundary transfer — includes captured `use` variables

## Gotchas
- **Swoole Thread adapter needs PHP built with ZTS** — most Appwrite Docker images don't have this; falls back to `Swoole\Process` silently
- **`opis/closure` can't serialize closures referencing non-serializable resources** (PDO handles, open sockets) — will error at runtime in parallel mode but work in Sync
- `Promise::run()` with the Sync adapter is literally blocking — drop-in API but no concurrency gain
- `Parallel::shutdown()` is manual only for custom pools; default pool shuts down at script exit — watch out in long-running Swoole workers where exit never fires

## Appwrite leverage opportunities
- **Replace ad-hoc `Swoole\Coroutine\run(fn() => ...)`** in services with `Promise::map()` for type-safe concurrent I/O (parallel DB reads, provider health checks) — adapter-agnostic so tests run on Sync without Swoole
- **CPU-bound jobs** (image resize, PDF render, ML inference inside functions runtime) should use `Parallel::pool($tasks, cpu_count)` instead of spawning subprocesses manually — gets thread-based execution for free on Swoole 6
- **Webhook fanout with idempotency**: `Promise::allSettled()` across N targets, then retry only `rejected` entries with exponential backoff via `Promise::delay()` — the `allSettled` shape makes per-target results inspectable
- **Timeout wrapper** (`->timeout(ms)`) is the right primitive for the proxy service's upstream calls — replaces bespoke guard code

## Example
```php
use Utopia\Async\Promise;

$results = Promise::all([
    Promise::async(fn () => file_get_contents('https://appwrite.io/api/a')),
    Promise::async(fn () => file_get_contents('https://appwrite.io/api/b')),
    Promise::async(fn () => file_get_contents('https://appwrite.io/api/c')),
])
    ->timeout(5_000)
    ->catch(fn (Throwable $e) => error_log($e->getMessage()))
    ->await();
```
