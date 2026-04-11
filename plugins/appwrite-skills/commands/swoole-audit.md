---
description: Audit a PHP + Swoole project for the full class of Swoole pitfalls
argument-hint: "[path]"
---

# /swoole-audit — Swoole Correctness & Safety Audit

Scan a PHP + Swoole project for the full space of Swoole-specific bug
classes: blocking I/O inside coroutines, unsafe shared state, long-
running-process footguns, hook/runtime misconfiguration, server tuning
issues, and incompatibilities with common extensions and libraries.

This skill is **generic** — it works on any Swoole project, whether
Appwrite, Hyperf, Octane, or a bespoke service. It is not opinionated
about the framework above Swoole.

Default `$ARGUMENTS` to `.` (current directory). If the `swoole-expert`
skill is available, consult it for primary reference — the audit
categories below mirror its pitfall catalog.

## Audit categories

Each category below is a parallel research question. Dispatch them as
independent subagents in a single message (see **Execution** section
further down). The summary is what to look for; the subagent should
return file:line references and one-line descriptions for each hit.

### 1. Runtime hooks and coroutine bootstrap
- **`Swoole\Runtime::enableCoroutine` / `Co::set` ordering.** Hook
  flags must be applied before any blocking I/O primitive (PDO, Redis,
  cURL, sockets) is instantiated. Resources constructed in the wrong
  order are not hooked and will block the scheduler.
- **`SWOOLE_HOOK_ALL` vs selective flags.** Selective hook flags can
  miss libraries the caller didn't expect (e.g. `file_put_contents`,
  `fopen` wrappers). Flag services that pick narrow flags when `ALL`
  would be safer — or the reverse when deliberate exclusions matter.
- **Missing hooks for specific drivers.** If the code uses
  `SWOOLE_HOOK_PDO_PGSQL`, `SWOOLE_HOOK_PDO_ORACLE`,
  `SWOOLE_HOOK_PDO_FIREBIRD`, `SWOOLE_HOOK_MONGODB`, or
  `SWOOLE_HOOK_NET_FUNCTION` but the flag isn't included in the
  runtime config, those calls block.

### 2. Blocking I/O inside coroutines
- **`sleep`** vs `Co::sleep` / `Timer::after`. The built-in `sleep`
  only yields if sleep is hooked; if not, it blocks the worker.
- **`pcntl_*` family** (`pcntl_fork`, `pcntl_wait`, `pcntl_signal`,
  `pcntl_alarm`). These are not hooked and are unsafe inside
  coroutines — use `Swoole\Process` or `Swoole\Process\Signal` instead.
- **Process-spawning functions** (`shell_exec`, `system`, `passthru`,
  the `proc_*` family, and the builtin `exec` function). Not hooked;
  block the reactor. Use `Swoole\Coroutine::exec` instead.
- **Synchronous `file_*` calls** when sync-file hook is disabled —
  `file_get_contents('http://...')`, `fwrite` to unhooked resources.
- **Third-party SDKs** using native extensions (`ext-mongodb`,
  `ext-mysqli` without mysqlnd, `ext-redis` without hook, `ext-imagick`,
  `ext-grpc`) — flag any use-site inside a coroutine context that
  isn't wrapped in a task worker or `Co\go` offload.
- **Synchronous cURL with `CURLOPT_RETURNTRANSFER`** and no
  `SWOOLE_HOOK_CURL` — blocks the reactor.

### 3. Shared state across coroutines
- **Connections stored in `static` / global / singleton state** and
  used from more than one coroutine: PDO, Redis, Mongo, HTTP clients,
  stream handles. Each must either be pooled, per-coroutine, or
  protected by a lock (lock = usually wrong choice under coroutines).
- **Mutable singleton services** (caches, request-local containers,
  loggers with internal buffers) that assume one-shot lifetime.
- **Request-scoped globals** (`$_SESSION`, `$_GET`, `$_POST`, `$_FILES`,
  `$_COOKIE`, `$_SERVER`). In long-running Swoole servers these carry
  across requests unless explicitly reset. Flag any code reading
  superglobals inside a server handler.
- **Static properties that accumulate** — class-level arrays that
  grow without bound (caches without eviction, sets of IDs, metric
  counters) leak memory over the worker's lifetime.

### 4. Connection pools
- **Pool construction context.** Pools created at file load or in
  static initializers run once in the parent before fork; each worker
  inherits stale handles and dies on first use. Pools must be built in
  `onWorkerStart` (or equivalent per-process init).
- **`put it back or leak`** — every `pop` / `get` / `borrow` must
  have a matching `push` / `put` / `release` in a `finally` block.
  A handler that throws without returning the resource starves the
  pool by one connection per crash.
- **Pool size** set independently of `worker_num`. A pool sized
  smaller than the per-worker concurrency limit deadlocks under load;
  sized too large starves the downstream service. Flag `size`
  literals and suggest deriving from
  `worker_num * max_coroutine_per_request`.
- **No health check on pop.** Pools that don't ping the resource
  before handing it back out will serve broken handles after a
  network blip.
- **`Channel` used as a pool** without timeout on `pop` — will hang
  a coroutine forever if the pool is empty and no-one pushes.

### 5. Long-running process footguns
- **`exit` / `die`** inside a server handler kills the worker, not
  the request. The server manager respawns but the in-flight request
  drops with no response. Flag every non-boot exit.
- **`echo`, `print`, `var_dump`, `print_r`** outside an explicit
  logging path. These write to STDOUT, not the HTTP response, and in
  FPM would appear in the response body by accident — in Swoole they
  leak to the server log and obscure real errors.
- **`header`, `setcookie`, `http_response_code`** — these are FPM
  idioms and no-op in Swoole's `Http\Server`. Responses must use
  `$response->header` / `$response->cookie` / `$response->status`.
- **`session_start`** — incompatible with Swoole's long-running
  model. Use the `Swoole\Session` wrapper or a session service
  explicitly.
- **`ini_set`** at runtime — affects the whole worker, not the
  request. Changes persist across subsequent requests on the same
  worker.
- **`set_error_handler` / `set_exception_handler`** registered per-
  request but never restored — stacks up across requests.

### 6. Concurrency primitives
- **Uncaught exceptions inside `go`** disappear silently — `go` does
  not propagate to the parent coroutine. Every `go {}` body should
  have a `try/catch` that logs or reports.
- **`defer` ordering** — defers run in LIFO order on coroutine exit.
  Flag any code that assumes FIFO.
- **`Swoole\Lock`** used from a coroutine — `Lock` is pthread-based
  and blocks the entire thread, not just the coroutine. Use
  `Channel` of size 1 or `Co\Barrier` instead.
- **`Channel` without buffer** (`new Channel(0)`) used as a queue —
  it's synchronous, so `push` blocks until `pop`; likely not what
  the author intended.
- **`WaitGroup::wait` without `add` called first** — returns
  immediately. Flag any wait without a matching add.
- **`Swoole\Barrier` missing `wait`** — just leaks if nothing waits.

### 7. HTTP / WebSocket / TCP server config
- **`buffer_output_size`, `package_max_length`, `socket_buffer_size`**
  defaults (2MB) are too small for large request bodies, file uploads,
  and WebSocket fanout. Flag servers that accept uploads and don't
  bump these.
- **`max_request`** unset or too high — memory leaks accumulate
  across requests; setting it to 1000–10000 forces graceful recycling
  and caps the blast radius of a leak.
- **`reload_async: true`** missing on servers that hold long
  connections — without it, SIGUSR1 kills in-flight connections.
- **`dispatch_mode`** left at default (2 = fixed by connection fd).
  Stateful modes (1/2/4) are incompatible with stateless HTTP
  handling; prefer 3 (preemptive) or 5 (stream) for HTTP servers.
- **`daemonize: true`** combined with Docker (which expects PID 1 in
  foreground) — daemonize kills the container immediately.
- **Task workers (`task_worker_num`) enabled but `onTask` / `onFinish`
  not registered** — `task_worker_num > 0` requires both callbacks.
- **Packet framing** missing on TCP servers — without
  `open_length_check` or `open_eof_check`, messages fragment and
  handlers receive partial frames.

### 8. Signal handling and shutdown
- **No graceful shutdown** — the server should handle SIGTERM by
  stopping accept, draining in-flight requests, and then exiting.
  A missing signal handler leaves the process to be SIGKILL'd.
- **`pcntl_signal` used instead of `Swoole\Process::signal`** —
  pcntl signals don't work once `enableCoroutine` is on.
- **`onWorkerStop`** missing cleanup of pooled resources — leaks
  connections into the post-fork state.

### 9. Shared memory (Table / Atomic / Lock)
- **`Swoole\Table` size not pre-sized** — tables are fixed-capacity
  at creation; `create` failing silently returns `false` and
  subsequent `set` calls no-op.
- **`Table` used for hot counters** where `Swoole\Atomic` would be
  faster and simpler.
- **`Atomic` without initial value** — defaults to 0 but some code
  assumes `null` / unset semantics.
- **`Swoole\Lock` used with `SWOOLE_MUTEX` in coroutine context** —
  see category 6.

### 10. Extensions and library incompatibility
- **Xdebug** loaded in a Swoole binary will crash on coroutine
  switches. Flag any `xdebug.*` ini directives or stepping hints in
  local dev config that might get shipped.
- **Blackfire, Tideways, New Relic, DataDog ddtrace** — most APM
  agents are partially or fully incompatible with coroutines. Flag
  their presence and recommend the caller verify the Swoole build.
- **Frameworks built for FPM** (Laravel without Octane, vanilla
  Symfony) wired into a Swoole server entry point — most will leak
  state across requests. Flag any `Illuminate\Foundation\Application`
  instantiated outside `onRequest`.
- **Guzzle, ReactPHP, Amp** co-existing with Swoole hooked cURL —
  generally OK but worth flagging so the reviewer verifies no double
  event loop.
- **`ext-parallel`, `ext-pthreads`** with Swoole — incompatible at
  the process level.

### 11. Testing
- **PHPUnit entry point** not wrapping tests in `Co\run` — coroutine
  code will silently skip yielding and produce false-green tests.
- **Server tests without state reset** — PHPUnit runs sequentially in
  one process, static state from one test bleeds into the next.
- **No `swoole/ide-helper`** installed — stubs and type hints silently
  resolve to `mixed` and hide bugs. Flag `composer.json` if missing.

## Execution

1. Normalize `$ARGUMENTS` → the target path, defaulting to `.`.

2. **Consult the `swoole-expert` skill** if available for primary
   reference. It has the full pitfall catalog, version notes, and
   code examples for everything above.

3. **Enumerate Swoole entry points.** Grep the target for
   `Swoole\Http\Server`, `Swoole\WebSocket\Server`, `Swoole\Server`,
   `Swoole\Process`, `Swoole\Process\Pool`, `Co\run`,
   `Swoole\Coroutine\run`. List every one with its file:line.

4. **Dispatch 11 parallel subagents in a single message**, one per
   category above. Each should:
   - Use Grep and Read only (no edits)
   - Scope to the target path, excluding `vendor/`, `tests/`, and
     `node_modules/` unless specifically requested
   - Return under 250 words per category
   - Include file:line for every finding
   - Classify severity (P0/P1/P2)

5. **Synthesize into a single prioritized report:**
   - **P0** — will cause incidents under load (blocking in coroutines,
     shared mutable connections, missing enableCoroutine, exit in
     handlers, exceptions lost in go blocks)
   - **P1** — will fail or leak eventually (no max_request, missing
     onWorkerStop cleanup, unrestored handlers, no pool health checks,
     Lock under coroutines, missing graceful shutdown)
   - **P2** — style/safety (imports, ini_set at runtime, missing
     ide-helper, superglobal reads, dispatch_mode default)

6. **For each finding:** file:line, one-line description, concrete
   suggested fix citing the relevant `swoole-expert` section if
   possible.

7. **Skip categories** that don't apply to the target (e.g. don't
   audit WebSocket config on a project with only an HTTP server).
   Note skipped categories in the report so the user sees what
   wasn't checked.

## Known false positives to suppress

- `tests/` directories — test fixtures often deliberately reuse
  handles and run in sync mode
- `vendor/` — third-party code; audit the top-level project only
- Files that use a pool abstraction the reviewer knows handles
  per-worker setup internally (ask the user which ones to trust)
- Files explicitly marked for FPM-only execution (`// @fpm-only`
  comments, separate entry point)

## Example output

```
## Swoole audit — ./src

Entry points found (3)
  bin/http.php:12    — Swoole\Http\Server
  bin/websocket.php:8 — Swoole\WebSocket\Server
  bin/worker.php:15  — Swoole\Server (TCP)

P0 findings (4)
  bin/http.php:18    — PDO constructed before Co::set hook_flags.
                       Move Runtime::enableCoroutine to the top of
                       the file with SWOOLE_HOOK_ALL.
  src/Service/Cache.php:24  — Redis handle stored in static $client,
                               reused across coroutines. Wrap in a
                               ConnectionPool or instantiate per-request.
  src/Http/Handler.php:87   — go body with no try/catch; exceptions
                               from the async path are silently lost.
  src/Http/Handler.php:142  — hard exit inside request handler kills
                               the whole worker.

P1 findings (3)
  bin/http.php:41    — max_request not set; long-lived leaks will
                       accumulate. Set max_request: 5000.
  src/Worker/Pool.php:30 — Pool size hardcoded to 10 but server
                            worker_num is 8 with 100 concurrent
                            requests/worker. Pool will deadlock.
  bin/http.php:52    — No onShutdown / SIGTERM handler registered.

P2 findings (2)
  src/Http/Middleware.php:19 — $_SERVER read inside handler — carries
                                across requests under dispatch_mode 3.
                                Use $request->server instead.
  composer.json      — swoole/ide-helper not installed; type checking
                       won't catch Swoole API misuse.

Categories skipped:
  - Signal handling (no TCP server listens for custom signals)
  - Task workers (task_worker_num = 0)
```

End the report with a concrete next step, e.g.:
`Run /fanout fix the P0 findings above` or
`Consult skill swoole-expert for fix patterns`.
