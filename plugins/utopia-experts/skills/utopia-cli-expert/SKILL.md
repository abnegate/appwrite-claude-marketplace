---
name: utopia-cli-expert
description: Expert reference for utopia-php/cli — lightweight CLI framework with task DSL, hooks, DI, and optional Swoole worker pooling. Consult when building Appwrite bin/* tasks, long-running workers, or when adding Swoole coroutines to batch jobs.
---

# utopia-php/cli Expert

## Purpose
Lightweight library for building command-line applications on top of the Utopia framework with a task/hook DSL and optional Swoole worker pooling.

## Public API
- `Utopia\CLI\CLI` — main container: parses argv, resolves tasks, runs init/error/shutdown hooks
- `Utopia\CLI\Task` — declares a named task with params, description, labels, action
- `Utopia\CLI\Adapter` — abstract base exposing `start/stop/onWorkerStart/onWorkerStop/onJob`
- `Utopia\CLI\Adapters\Generic` — single-process adapter; executes callback synchronously
- `Utopia\CLI\Adapters\Swoole` — `Swoole\Process\Pool`-based adapter enabling coroutine runtime and multi-worker processes
- `Utopia\Servers\Hook` (inherited) — init/error/shutdown hook definitions with param/inject support
- `Utopia\DI\Container` — DI container shared with tasks via `CLI::setResource()` / `inject()`
- `Utopia\Console` (companion) — colored output helpers (`success`, `error`, `info`, `warning`)

## Core patterns
- **Task DSL**: `$cli->task('name')->param(...)->inject(...)->action(fn(...) => ...)` mirrors the framework's HTTP route API
- **Hook chain** runs init hooks → task action → shutdown hooks; any thrown `Throwable` triggers error hooks in order
- **Resource injection** via shared static `CLI::setResource()` — same mechanism as Utopia HTTP app
- **Swoole adapter enables `Swoole\Runtime::enableCoroutine()`** so blocking I/O inside tasks becomes async
- Process title is set via `cli_set_process_title()` to the current command for easier `ps` introspection

## Gotchas
- Constructor **throws if `php_sapi_name() !== 'cli'`** — you cannot embed the CLI in a web request
- `Generic` adapter's `onJob` is synchronous; `Swoole` adapter requires `ext-swoole >= 4.x` and only starts the pool inside `start()`
- Depends on `utopia-php/servers: 0.3.*` (Hook class comes from there, not this package)
- `cli_set_process_title()` is suppressed (`@`) — it silently fails on macOS / restricted kernels, so you cannot rely on it for PID discovery

## Appwrite leverage opportunities
- **Compose long-running workers** (`schedule`, `worker-*`, `sdks`) as Swoole-pool tasks so a single `doctor`-style task can inject and reuse `Pools`, `Registry`, `Cache` via `CLI::setResource` instead of re-bootstrapping each script
- **Use init hooks to warm up heavy resources** (GeoIP, DNS providers, Stripe SDK) once per worker instead of per-invocation — combine with `utopia-php/preloader` for near-zero startup
- **Error hooks are a natural place** to ship CLI failures into `utopia-php/logger` alongside the existing HTTP error pipeline for unified Sentry/AppSignal alerting
- **Swoole worker pool is underused**: batch migrations, bulk-index rebuilds and SDK generation could be parallelised across `swoole_cpu_num()` workers without pulling in `utopia-php/queue`

## Example
```php
use Utopia\CLI\CLI;
use Utopia\CLI\Adapters\Swoole;
use Utopia\Console;
use Utopia\Validator\Wildcard;

CLI::setResource('registry', fn () => new Registry());

$cli = new CLI(new Swoole(workerNum: 4));
$cli->init()->inject('registry')->action(fn ($registry) => $registry->boot());
$cli->error()->inject('error')->action(fn ($error) => Console::error($error->getMessage()));
$cli->task('migrate')
    ->param('version', null, new Wildcard())
    ->inject('registry')
    ->action(fn ($version, $registry) => Console::success("Migrating to {$version}"));
$cli->run();
```
