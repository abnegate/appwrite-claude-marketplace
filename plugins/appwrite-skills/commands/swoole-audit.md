---
description: Audit a PHP+Swoole project for pool, coroutine, and shared-state bugs
argument-hint: "[path]"
---

# /swoole-audit — Swoole Pool & Coroutine Lint

Scan a PHP + Swoole project for the specific bug classes that have
repeatedly bitten the Appwrite cloud/edge codebases:

1. **Pool exhaustion from shared singletons.** Pools created at boot and
   referenced statically, then exhausted because every worker shares the
   same handle instead of getting its own.
2. **`enableCoroutine` / `Co::set` placement.** Must be called at the very
   top of the process entry point, before any pool or client is
   instantiated. Late calls fail silently — sockets don't hook properly.
3. **Shared Redis / DB sockets across coroutines.** A single socket driven
   by multiple coroutines interleaves protocol frames and corrupts
   responses.
4. **Missing `use Swoole\Coroutine as Co;` imports.** Causes a fatal at
   runtime, not compile time.
5. **Per-worker pool initialization missing.** Pools have to be rebuilt
   after `fork()`; static init at class load runs once in the parent and
   dies with the first worker restart.

## Execution

Default `$ARGUMENTS` to `.` (current repo).

1. **Gather entry points.** Look for `Swoole\Http\Server`,
   `Swoole\Server`, `Swoole\Process`, `Co\run` under `src/`, `app/`,
   `bin/`, `cli/`, `worker/`, `http/`. List every one.

2. **Check `enableCoroutine` ordering.** For each entry point, confirm
   the first executable statement is `Co::set([...])` or
   `Swoole\Runtime::enableCoroutine()`. Flag any entry point where a
   pool, cache, Redis, or database client is constructed before the
   hook call.

3. **Find pool instantiations.** Grep for `new SwoolePool`,
   `new Pool`, `ConnectionPool::getInstance`, `DatabasePool::`,
   `RedisPool::`. For each, determine whether it's:
   - Constructed in a per-worker context (inside `onWorkerStart` or the
     request handler). **Good.**
   - Constructed at file load, module init, or a static initializer.
     **Flag.**
   - Assigned to a `static` property and reused across workers.
     **Flag — this is the exhaustion pattern.**

4. **Find shared socket handles.** Grep for `Redis`, `PDO`, `MongoDB\Client`
   instantiations. Flag any that are stored in a static/global and
   accessed from more than one coroutine without a pool wrapping them.

5. **Verify Swoole imports.** Grep for files using `Co::`, `Coroutine::`,
   `Swoole\Coroutine` that lack the `use Swoole\Coroutine as Co;` import
   or equivalent. Flag each.

6. **Check `onWorkerStart` coverage.** Every server entry point should
   have an `on('WorkerStart', ...)` that reinitializes pools. Flag any
   that don't.

7. **Dispatch as subagents.** Run steps 1-6 as parallel `Explore`
   subagents in a single message. Each should report under 200 words
   with file:line references.

8. **Output a prioritized finding list.**
   - **P0** — will cause production incidents (pool exhaustion, shared
     sockets, missing `enableCoroutine`).
   - **P1** — will fail under load (missing per-worker init, static
     pool references).
   - **P2** — style/safety (missing imports, unused Swoole classes).

   For each finding: file:line, one-line description, suggested fix.

## Known false positives to suppress

- `tests/` directories — test fixtures often deliberately reuse handles.
- `vendor/` — third-party code.
- Files that import a pool abstraction from `Utopia\Pools` — that class
  handles per-worker setup internally.

## Example output

```
## Swoole audit — cloud/

P0 findings (3)
  app/http.php:47  — Pool instantiated before Co::set hook_flags. Move enableCoroutine to line 1.
  src/Appwrite/DB.php:23 — static $pool shared across workers. Move into onWorkerStart.
  src/Appwrite/Cache.php:15 — Redis client stored in static property, reused across coroutines without pool.

P1 findings (1)
  app/worker.php:89 — on('WorkerStart') callback does not reinitialize DatabasePool.

P2 findings (2)
  src/Appwrite/Handler.php:12 — Co:: referenced without `use Swoole\Coroutine as Co` import.
  src/Appwrite/Queue.php:8 — Swoole\Coroutine imported but never used.
```

End with: "Run `/fanout fix the P0 findings above` to dispatch a fix pass."
