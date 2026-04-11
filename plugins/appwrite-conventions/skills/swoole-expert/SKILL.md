---
name: swoole-expert
description: Deep reference for writing production Swoole PHP code. Covers coroutines, runtime hooks, Channel/WaitGroup/Barrier, HTTP/WebSocket/TCP servers, processes, Table shared memory, connection pools, the long-running process mental model, and every common pitfall. Use when writing or reviewing Swoole code (swoole/swoole-src, NOT openswoole) on Swoole 5.x/6.x with PHP 8.2+.
---

# Swoole Expert

Reference for **swoole/swoole-src** (NOT openswoole). Target: **Swoole 5.x/6.x**, **PHP 8.2+**. Every signature below has been verified against upstream stubs, docs, or release notes.

When using this skill:
- Prefer idiomatic `Co\run` / `go()` / `Co::defer` patterns
- Always enable `SWOOLE_HOOK_ALL` so native PHP I/O becomes coroutine-aware
- Never share a connection across coroutines — use a pool
- Remember workers are **resident** processes, not short-lived like php-fpm

## Table of contents

This skill is long (~1,650 lines). For targeted lookups, jump directly
to the section you need. The `/swoole-audit` and `/swoole-fix` commands
cite these anchors by name (e.g. `swoole-expert#3-swoole-runtime-enablecoroutine-and-hook-flags`).

1. [The long-running process mental model](#1-the-long-running-process-mental-model)
   — Four object lifetimes; what breaks vs php-fpm (superglobals, `session_start`,
   `echo`, `exit`, `header`, static state); max_request and worker recycling.
2. [Coroutines — `go()`, `Co\run()`, the scheduler](#2-coroutines--go-corun-the-scheduler)
   — Cooperative scheduling, parent/child priority, introspection, cancellation,
   `setTimeLimit`.
3. [`Swoole\Runtime::enableCoroutine()` and hook flags](#3-swoolerunntimeenablecoroutine-and-hook-flags)
   — Every `SWOOLE_HOOK_*` flag including 6.2 additions (`PDO_FIREBIRD`, `MONGODB`,
   `NET_FUNCTION`); what's not hookable; ordering requirements.
4. [Concurrency primitives — Channel, WaitGroup, Barrier, defer](#4-concurrency-primitives--channel-waitgroup-barrier-defer)
   — `Channel`, `WaitGroup`, `Barrier`, `defer`, `batch`/`parallel`/`map`, `Timer`.
5. [HTTP / WebSocket / TCP servers](#5-http--websocket--tcp-servers)
   — `Http\Server`, `WebSocket\Server`, TCP with packet framing, all events,
   `Request`/`Response` API, task workers, dispatch modes, graceful reload.
6. [`Swoole\Process` and `Swoole\Process\Pool`](#6-swooleprocess-and-swooleprocesspool)
   — Signal handling, IPC, when to use each.
7. [Shared memory — `Table`, `Atomic`, `Lock`](#7-shared-memory--table-atomic-lock)
   — Table sizing, Atomic counters, and why `Lock` isn't coroutine-safe.
8. [Coroutine clients](#8-coroutine-clients)
   — `Http\Client`, `Http2\Client`, `Socket`, hooked PDO + cURL (Guzzle works).
9. [Connection pooling — `swoole/library`](#9-connection-pooling--swoolelibrary)
   — `ConnectionPool`, `PDOPool`, `RedisPool`, `MysqliPool`, channel-as-pool
   pattern, "put it back or leak" rule.
10. [Pitfalls catalog](#10-pitfalls-catalog)
    — Blocking inside coroutines, sharing connections, `pcntl_*`, deadlocks,
    cooperative ≠ concurrent-safe, exception handling, framework compatibility.
11. [Production tuning](#11-production-tuning)
    — `worker_num`, `max_request`, `reload_async`, buffers, dispatch modes,
    kernel sysctls.
12. [Debugging](#12-debugging)
    — Live introspection, Xdebug incompatibility, GDB with gdbinit.
13. [Testing](#13-testing)
    — PHPUnit entry point, state reset, `swoole/ide-helper`.
14. [Swoole 6.x version notes](#14-swoole-6x-version-notes)
    — What's new/removed in 6.0, 6.1, 6.2, build flags, `swoole/library` version alignment.
15. [Quick reference — canonical program skeleton](#quick-reference--canonical-program-skeleton)
    — A minimal production-shaped entry point with every major concept wired.

---

## 1. The long-running process mental model

Swoole workers are **resident PHP processes**. Unlike php-fpm, PHP's memory manager is NOT torn down between requests. Everything you allocate at class, file, or worker scope survives across every request that worker handles.

### Four object lifetimes (from upstream `doc/2.7.11`)

| Lifetime | Where created | Destroyed by |
|---|---|---|
| **Program-global** | Before `$server->start()` | Full process shutdown (`reload` will NOT refresh) |
| **Process-global** | In `onWorkerStart` | `max_request` reached, worker crash, or reload |
| **Session** | `onConnect` / first `onReceive` | `onClose` |
| **Request** | Inside the request handler | End of request |

Mental rule: if you allocated it in a hot path, assume it's a leak unless you can point to where it's freed.

### What does NOT work (vs. php-fpm)

- **Superglobals**: `$_GET`, `$_POST`, `$_COOKIE`, `$_FILES`, `$_SERVER`, `$_REQUEST`, `$_SESSION` are NOT populated. Use `$request->get`, `$request->post`, `$request->cookie`, `$request->files`, `$request->server`, `$request->header`, `$request->rawContent()`.
- **`session_start()`** — implement sessions with your own Redis/DB-backed handler keyed by a cookie you read from `$request->cookie`.
- **`echo` / `print_r` / `var_dump`** — go to stdout (which is redirected to `log_file` when daemonized), NOT to the client. Use `$response->write($chunk)` for streaming and `$response->end($body)` to flush.
- **`exit()` / `die()`** — kills the current worker. Swoole 4.1+ converts it into `Swoole\ExitException` you can catch, but idiomatic code throws a domain exception and catches at the `onRequest` boundary. Never use `exit` for control flow.
- **`header()` / `setcookie()`** — silent no-ops. Use `$response->header($key, $value)`, `$response->cookie(...)`, `$response->status($code)`. Must be called BEFORE `$response->end()`.
- **Static properties / singletons / `global`** — leak request state across concurrent coroutines. Store per-request state in `Coroutine::getContext()` instead.
- **`pcntl_*`** — forbidden in coroutines. Use `Swoole\Process` and `Swoole\Process::signal()`.
- **Xdebug, phptrace, aop, molten, xhprof, phalcon** — extensions incompatible with Swoole coroutines. Disable them in the Swoole SAPI.

### Per-request state with `Coroutine::getContext()`

```php
use Swoole\Coroutine;

// Context is a Swoole\Coroutine\Context (extends ArrayObject),
// automatically destroyed when the coroutine exits — no cleanup needed.
$ctx = Coroutine::getContext();
$ctx['user_id']   = 42;
$ctx['requestId'] = bin2hex(random_bytes(8));

// Downstream code can read it independently:
innerFunction();

function innerFunction(): void
{
    $ctx = Coroutine::getContext();
    $userId = $ctx['user_id'];
}
```

Do NOT stash `$this` in the context unless you want to keep the controller alive for the coroutine's lifetime — it holds a strong reference.

---

## 2. Coroutines — `go()`, `Co\run()`, the scheduler

Swoole coroutines are stackful user-space threads scheduled by a single event loop per process. **One process, one scheduler.** Coroutines cannot span CPU cores — use `Swoole\Process` or the Server multi-process model for that.

### Creating and running coroutines

```php
// Create
Swoole\Coroutine::create(callable $fn, mixed ...$args): int|false
go(callable $fn, mixed ...$args): int|false  // short alias (requires php.ini swoole.use_shortname=On, default)

// Top-level scheduler entry point
Swoole\Coroutine\run(callable $fn): bool
Co\run(callable $fn): bool  // alias
```

All coroutine-creating APIs must run inside a **coroutine container** — either `Co\run()`, a Swoole server event callback (with `enable_coroutine = true`, default), or a `Process`/`Process\Pool` worker with `enable_coroutine = true`.

```php
use Swoole\Coroutine;
use function Swoole\Coroutine\run;

run(function (): void {
    go(function (): void {
        Coroutine::sleep(0.1);
        echo "child done\n";
    });
    echo "parent done\n"; // prints first, then child
});
```

`run()` returns only once all coroutines have exited, timers drained, sockets closed. Nesting `run()` inside another `run()` is forbidden.

### Scheduling is cooperative

A coroutine runs until it hits a yield point:
- A hooked I/O operation (with `Runtime::enableCoroutine()`)
- `Co::sleep()` / `System::sleep()`
- `Channel::push()` on a full channel, or `Channel::pop()` on an empty one
- `Coroutine::yield()` / `Coroutine::suspend()`
- `WaitGroup::wait()` / `Barrier::wait()`

**A CPU-bound loop with no I/O monopolizes the worker.** Sprinkle `Coroutine::sleep(0)` or `Co::yield()` to voluntarily yield, or dispatch CPU work to a task worker.

### Parent/child priority gotcha

When you call `go()`, the **child starts immediately** and runs until its first yield. Only then does `go()` return to the parent:

```php
echo "a\n";
go(function () {
    echo "b\n";
    Co::sleep(0.1);   // yields here
    echo "d\n";
});
echo "c\n";
// Output: a, b, c, d
```

Coroutines have no real parent/child lifecycle — a parent exiting does not cancel or wait for children. Use `Barrier` or `WaitGroup` to wait explicitly.

### Introspection

```php
Swoole\Coroutine::getCid(): int                   // -1 if outside coroutine
Swoole\Coroutine::getPcid(int $cid = 0): int|false
Swoole\Coroutine::exists(int $cid): bool
Swoole\Coroutine::list(): Swoole\Coroutine\Iterator
Swoole\Coroutine::stats(): array                  // coroutine_num, coroutine_peak_num, ...
Swoole\Coroutine::getBackTrace(int $cid = 0, int $options = DEBUG_BACKTRACE_PROVIDE_OBJECT, int $limit = 0): array
Swoole\Coroutine::printBackTrace(int $cid = 0): void
Swoole\Coroutine::getElapsed(int $cid = 0): int   // milliseconds alive
```

### Global coroutine configuration

```php
Swoole\Coroutine::set(array $options): void
Swoole\Coroutine::getOptions(): ?array
```

| Option | Purpose |
|---|---|
| `max_coroutine` | Global coroutine limit (default 100000) |
| `stack_size` / `c_stack_size` | C stack per coroutine, default 2 MB |
| `hook_flags` | One-click hook flags, e.g. `SWOOLE_HOOK_ALL` |
| `enable_preemptive_scheduler` | Force preemption at 10 ms |
| `socket_connect_timeout` | Default connect timeout |
| `socket_read_timeout` / `socket_write_timeout` / `socket_timeout` | Defaults |
| `dns_cache_expire` / `dns_cache_capacity` / `dns_server` | DNS defaults |
| `exit_condition` | `callable(): bool` — custom reactor exit |
| `enable_deadlock_check` | On by default |
| `log_level` / `trace_flags` | Logging/tracing |

Call `Co::set()` **before** `run()` / `Server->start()`.

### Cancellation and exit

```php
Swoole\Coroutine::cancel(int $cid, bool $throwException = false): bool  // $throwException added 6.1
Swoole\Coroutine::isCanceled(): bool
Swoole\Coroutine::yield(): void
Swoole\Coroutine::resume(int $cid): bool
Swoole\Coroutine::setTimeLimit(float $seconds): void  // 6.2+
```

**Caveat**: `cancel()` cannot cancel file I/O coroutines; forcing it may segfault. Since 6.2 it can cancel in-flight io_uring ops.

---

## 3. `Swoole\Runtime::enableCoroutine()` and hook flags

Hooks patch PHP's blocking stdlib to yield on I/O. Set **once** at bootstrap:

```php
// Preferred — at startup:
Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

// Or dynamically:
Swoole\Runtime::enableCoroutine(int $flags = SWOOLE_HOOK_ALL): bool
Swoole\Runtime::setHookFlags(int $flags): bool
Swoole\Runtime::getHookFlags(): int
```

Or as a server option (equivalent, applied before user code in workers):

```php
$server->set(['hook_flags' => SWOOLE_HOOK_ALL]);
```

### Flag reference (all flags in ext-swoole 6.x)

| Flag | Covers |
|---|---|
| `SWOOLE_HOOK_TCP` | TCP `stream_socket_client`, `fsockopen`, native `Redis`, PDO_MYSQL (mysqlnd), mysqli (mysqlnd), predis, php-amqplib, etc. |
| `SWOOLE_HOOK_UNIX` | Unix-domain stream sockets |
| `SWOOLE_HOOK_UDP` / `SWOOLE_HOOK_UDG` | UDP / Unix datagram |
| `SWOOLE_HOOK_SSL` / `SWOOLE_HOOK_TLS` | TLS streams |
| `SWOOLE_HOOK_SLEEP` | `sleep`, `usleep` (≥ 1 ms), `time_nanosleep`, `time_sleep_until` |
| `SWOOLE_HOOK_FILE` | `fopen`, `fread`, `fgets`, `fwrite`, `file_get_contents`, `file_put_contents`, `unlink`, `mkdir`, `rmdir`. Uses AIO workers, or **io_uring** in 6.0+ with `--enable-iouring` |
| `SWOOLE_HOOK_STREAM_FUNCTION` | `stream_select()` (alias: `SWOOLE_HOOK_STREAM_SELECT`) |
| `SWOOLE_HOOK_BLOCKING_FUNCTION` | `gethostbyname`, `shell_*` functions |
| `SWOOLE_HOOK_PROC` | `proc_open`, `proc_close`, `proc_get_status`, `proc_terminate` |
| `SWOOLE_HOOK_NATIVE_CURL` | Real libcurl coroutinized — **use this**, not the legacy `SWOOLE_HOOK_CURL`. Requires `--enable-swoole-curl`. Guzzle and Symfony HttpClient work transparently. |
| `SWOOLE_HOOK_CURL` | **Legacy** partial curl reimplementation. Avoid in new code. |
| `SWOOLE_HOOK_SOCKETS` | ext-sockets extension. Auto-dropped in 6.1.6+ if ext-sockets isn't loaded. |
| `SWOOLE_HOOK_STDIO` | STDIN/STDOUT/STDERR read/write |
| `SWOOLE_HOOK_PDO_PGSQL` | `pdo_pgsql` (5.1+; 6.1.7 added timeout support) |
| `SWOOLE_HOOK_PDO_ODBC` | `pdo_odbc` (5.1+) |
| `SWOOLE_HOOK_PDO_ORACLE` | `pdo_oci` (5.1+). **Constant is `_ORACLE`, not `_OCI`** |
| `SWOOLE_HOOK_PDO_SQLITE` | `pdo_sqlite` (5.1+) — requires sqlite built serialized/multi-thread |
| `SWOOLE_HOOK_PDO_FIREBIRD` | `pdo_firebird` (**new in 6.2**) |
| `SWOOLE_HOOK_MONGODB` | MongoDB (**new in 6.2** via `Swoole\RemoteObject\Server`) |
| `SWOOLE_HOOK_NET_FUNCTION` | coroutine `gethostbyname` (6.2+) |
| `SWOOLE_HOOK_ALL` | All of the above |

### Not hookable (degrade to blocking — don't use in a coroutine)

- `mysql` extension (libmysqlclient)
- `mongo` / `mongodb` (mongo-c-client) — use the 6.2+ MongoDB hook instead
- `php-amqp` (the C AMQP ext — but `php-amqplib` over streams works)
- Any extension that bypasses PHP's streams layer

`pdo_mysql` and `mysqli` are hookable **only in mysqlnd mode**. Check with `php -m | grep mysqlnd`.

### Canonical bootstrap

```php
Co::set([
    'hook_flags'             => SWOOLE_HOOK_ALL,
    'max_coroutine'          => 100_000,
    'socket_connect_timeout' => 2.0,
    'socket_timeout'         => 30.0,
    'log_level'              => SWOOLE_LOG_WARNING,
]);
```

---

## 4. Concurrency primitives — Channel, WaitGroup, Barrier, defer

### `Swoole\Coroutine\Channel` — CSP channel

In-process only, zero-copy (refcounted). Cannot cross process boundaries.

```php
final class Swoole\Coroutine\Channel {
    public int $capacity;
    public int $errCode;  // SWOOLE_CHANNEL_OK | _TIMEOUT | _CLOSED | _CANCELED

    public function __construct(int $capacity = 1);
    public function push(mixed $data, float $timeout = -1): bool;
    public function pop(float $timeout = -1): mixed;
    public function close(): bool;
    public function length(): int;
    public function isEmpty(): bool;
    public function isFull(): bool;
    public function stats(): array;
}
```

**Gotchas**:
- Pushing `false`/`null`/`0` is ambiguous because `pop()` also returns `false` on close/timeout. Always check `$chan->errCode` after a `false` return.
- `close()` wakes ALL waiting producers and consumers; they all return `false`.
- With `Swoole\Server`, create channels in `onWorkerStart`, not before `start()`.

```php
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

run(function () {
    $chan = new Channel(16);

    go(function () use ($chan) {
        for ($i = 0; $i < 10; $i++) {
            $chan->push(['i' => $i]);
        }
        $chan->close();
    });

    go(function () use ($chan) {
        while (true) {
            $msg = $chan->pop(2.0);
            if ($msg === false) {
                if ($chan->errCode === SWOOLE_CHANNEL_CLOSED) break;
                if ($chan->errCode === SWOOLE_CHANNEL_TIMEOUT) {
                    echo "timeout\n";
                    continue;
                }
            }
            var_dump($msg);
        }
    });
});
```

**Channel as semaphore** (capacity N, pre-filled):

```php
$sem = new Channel(5);
for ($i = 0; $i < 5; $i++) $sem->push(true);

go(function () use ($sem) {
    $sem->pop();
    try {
        doLimitedWork();
    } finally {
        $sem->push(true);
    }
});
```

### `Swoole\Coroutine\WaitGroup` (swoole/library)

```php
final class Swoole\Coroutine\WaitGroup {
    public function __construct(int $delta = 0);
    public function add(int $delta = 1): void;
    public function done(): void;
    public function wait(float $timeout = -1): bool;  // false on timeout
    public function count(): int;
}
```

Always call `done()` in a `finally` — if a worker throws and `done()` is skipped, `wait()` hangs until timeout.

```php
use Swoole\Coroutine\WaitGroup;
use function Swoole\Coroutine\run;

run(function () {
    $wg = new WaitGroup();
    $results = [];

    foreach (['a.example', 'b.example', 'c.example'] as $host) {
        $wg->add();
        go(function () use ($wg, $host, &$results) {
            try {
                $results[$host] = file_get_contents("https://{$host}/");
            } finally {
                $wg->done();
            }
        });
    }

    $wg->wait(10.0);
    var_dump($results);
});
```

### `Swoole\Coroutine\Barrier` (swoole/library, ≥ 4.5.5)

**Preferred over WaitGroup for new code** — you can't forget a `done()` call.

```php
final class Swoole\Coroutine\Barrier {
    public static function make(): self;
    public static function wait(Barrier &$barrier, float $timeout = -1): void;
}
```

Pass the barrier into each child via closure `use` — every closure capturing it bumps the refcount; each exiting child drops it. When the count reaches zero, the barrier's destructor resumes the waiter.

```php
use Swoole\Coroutine\Barrier;
use function Swoole\Coroutine\run;

run(function () {
    $barrier = Barrier::make();
    $count   = 0;

    for ($i = 0; $i < 4; $i++) {
        go(function () use ($barrier, &$count) {
            Co::sleep(0.5);
            $count++;
        });
    }

    Barrier::wait($barrier);  // by reference; nulled after wait
    assert($count === 4);
});
```

`wait()` takes the barrier **by reference** and a given barrier can only be waited on once. If you forget `use ($barrier)` the child doesn't hold a ref and `wait()` returns immediately.

### `Swoole\Coroutine::defer()` — Go-style cleanup

```php
Swoole\Coroutine::defer(callable $fn): void
defer(callable $fn): void  // short alias
```

Registers a callback that runs when the current coroutine exits — even on exception. **LIFO order** (last registered runs first).

```php
go(function () {
    $db = new PDO(/* ... */);
    defer(fn() => $db = null);  // runs second

    $stmt = $db->prepare('SELECT ...');
    defer(fn() => $stmt->closeCursor());  // runs first

    $stmt->execute();
});
```

### Batch primitives

```php
// Wait for multiple coroutines by cid (>= 4.8)
Swoole\Coroutine::join(array $cids, float $timeout = -1): bool

// Concurrent map over callables (>= 4.5.2)
Swoole\Coroutine\batch(array $tasks, float $timeout = -1): array

// Launch N copies of $fn (>= 4.5.3)
Swoole\Coroutine\parallel(int $n, callable $fn): void

// Concurrent array_map (>= 4.5.5)
Swoole\Coroutine\map(array $list, callable $fn, float $timeout = -1): array
```

```php
use function Swoole\Coroutine\batch;
use function Swoole\Coroutine\run;

Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

run(function () {
    $results = batch([
        'home' => fn() => file_get_contents('https://example.com/'),
        'api'  => fn() => file_get_contents('https://api.example.com/status'),
    ], 5.0);
});
```

### Timers — `Swoole\Timer`

```php
Swoole\Timer::tick(int $msec, callable $cb, mixed ...$params): int
Swoole\Timer::after(int $msec, callable $cb, mixed ...$params): int
Swoole\Timer::clear(int $timer_id): bool
Swoole\Timer::clearAll(): bool
Swoole\Timer::info(int $timer_id): ?array
Swoole\Timer::list(): Swoole\Timer\Iterator
Swoole\Timer::stats(): array
```

Callback signature: `function(int $timerId, mixed ...$params): void`. In a coroutine container, timer callbacks automatically run inside a new coroutine — you can call hooked I/O directly.

---

## 5. HTTP / WebSocket / TCP servers

### `Swoole\Http\Server` — canonical skeleton

```php
<?php
declare(strict_types=1);

use Swoole\Http\Server;
use Swoole\Http\Request;
use Swoole\Http\Response;

$server = new Server('0.0.0.0', 9501, SWOOLE_BASE);

$server->set([
    'worker_num'            => swoole_cpu_num() * 2,
    'task_worker_num'       => 4,
    'task_enable_coroutine' => true,
    'max_request'           => 10_000,
    'max_wait_time'         => 60,
    'reload_async'          => true,
    'enable_coroutine'      => true,     // default true
    'hook_flags'            => SWOOLE_HOOK_ALL,
    'http_compression'      => true,
    'log_file'              => '/var/log/swoole.log',
    'log_level'             => SWOOLE_LOG_INFO,
    'package_max_length'    => 8 * 1024 * 1024,
]);

$server->on('request', function (Request $request, Response $response): void {
    $method = $request->server['request_method'] ?? 'GET';
    $uri    = $request->server['request_uri']    ?? '/';
    $body   = $request->rawContent();

    $response->status(200);
    $response->header('Content-Type', 'application/json');
    $response->cookie('sid', bin2hex(random_bytes(16)), time() + 3600, '/', '', true, true, 'Lax');
    $response->end(json_encode(['method' => $method, 'uri' => $uri, 'len' => strlen($body)]));
});

$server->on('workerStart', function (Server $server, int $workerId): void {
    // Reset per-worker state, open DB/Redis coroutine pools here
});

$server->start();
```

### Server constructor and modes

```php
new Swoole\Server(
    string $host = '0.0.0.0',
    int $port = 0,
    int $mode = SWOOLE_BASE,           // SWOOLE_BASE (default), SWOOLE_PROCESS, SWOOLE_THREAD (6.0+ ZTS)
    int $sock_type = SWOOLE_SOCK_TCP
)
```

`Http\Server` and `WebSocket\Server` extend `Swoole\Server` with the same constructor. SSL: OR the sock type with `SWOOLE_SSL` and set `ssl_cert_file`/`ssl_key_file`.

**Default mode changed from `SWOOLE_PROCESS` to `SWOOLE_BASE` in 5.0.** `SWOOLE_BASE` has no manager process — workers accept connections directly.

### All server events

```
onStart(Server $server)                    // master start; NOT in SWOOLE_BASE mode
onShutdown(Server $server)
onManagerStart(Server $server)             // NOT in SWOOLE_BASE mode
onManagerStop(Server $server)
onBeforeReload(Server $server)             // 4.5+
onAfterReload(Server $server)              // 4.5+
onWorkerStart(Server $server, int $workerId)
onWorkerStop(Server $server, int $workerId)
onWorkerExit(Server $server, int $workerId)   // fires when reload_async and worker is draining
onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal)
onBeforeShutdown(Server $server)           // 4.8+
onPipeMessage(Server $server, int $srcWorkerId, mixed $message)

// TCP
onConnect(Server $server, int $fd, int $reactorId)
onReceive(Server $server, int $fd, int $reactorId, string $data)
onPacket(Server $server, string $data, array $clientInfo)   // UDP
onClose(Server $server, int $fd, int $reactorId)

// Task workers
onTask(Server $server, Swoole\Server\Task $task)  // or (Server, int $taskId, int $srcWorkerId, mixed $data)
onFinish(Server $server, int $taskId, mixed $data)

// HTTP
onRequest(Swoole\Http\Request $request, Swoole\Http\Response $response)

// WebSocket
onHandShake(Http\Request $request, Http\Response $response): bool
onOpen(WebSocket\Server $server, Http\Request $request)
onMessage(WebSocket\Server $server, WebSocket\Frame $frame)
```

### Core server methods

```php
public function set(array $settings): bool;
public function on(string $event, callable $callback): bool;
public function start(): bool;
public function stop(int $workerId = -1): bool;
public function shutdown(): bool;
public function reload(bool $onlyReloadTaskworker = false): bool;

public function send(int|string $fd, string $data, int $serverSocket = -1): bool;
public function close(int $fd, bool $reset = false): bool;
public function exists(int $fd): bool;
public function pause(int $fd): bool;
public function resume(int $fd): bool;

public function task(mixed $data, int $workerIdx = -1, ?callable $finishCb = null): int|false;
public function taskwait(mixed $data, float $timeout = 0.5, int $workerIdx = -1): mixed;
public function taskCo(array $tasks, float $timeout = 0.5): array|false;
public function finish(mixed $data): bool;

public function sendMessage(mixed $message, int $dstWorkerId): bool;
public function addProcess(Swoole\Process $process): int|false;

public function getClientInfo(int $fd, int $reactorId = -1): false|array;
public function getWorkerId(): int|false;
public function stats(): array;
public function heartbeat(bool $ifCloseConnection = true): false|array;
```

### `Swoole\Http\Request` properties

```php
public int    $fd;
public int    $streamId;
public array  $header;     // lowercase keys
public array  $server;     // request_method, request_uri, query_string, request_time, remote_addr, ...
public ?array $cookie;
public array  $get;
public array  $post;
public array  $files;      // same as $_FILES
public string $tmpfiles;

public function rawContent(): string|false;   // alias getContent()
public function getMethod(): string|false;
```

### `Swoole\Http\Response` methods

```php
public function status(int $httpCode, string $reason = ''): bool;
public function header(string $key, string|array $value, bool $format = true): bool;
public function cookie(string $name, string $value = '', int $expires = 0,
    string $path = '/', string $domain = '', bool $secure = false, bool $httponly = false,
    string $samesite = '', string $priority = ''): bool;
public function trailer(string $key, string $value): bool;
public function write(string $content): bool;     // chunked; disables compression
public function end(?string $content = null): bool;
public function sendfile(string $filename, int $offset = 0, int $length = 0): bool;
public function redirect(string $location, int $httpCode = 302): bool;
public function detach(): bool;

// Upgrade to WebSocket from an HTTP server
public function upgrade(): bool;
public function push(Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = WEBSOCKET_FLAG_FIN): bool;
public function recv(float $timeout = 0): Frame|string|false;
public function close(): bool;
```

**Gotchas**:
- `end()` can only be called **once**.
- `status()`/`header()`/`cookie()` must be called **before** the first `write()` or `end()`.
- `write()` switches to chunked transfer and disables compression.

### Worker model

- **Master**: listens on sockets; only exists in `SWOOLE_PROCESS` mode for TCP servers (in `SWOOLE_BASE`, workers accept directly).
- **Manager**: forks/supervises workers; only in `SWOOLE_PROCESS` mode.
- **Event workers** (`worker_num`): handle requests. With `enable_coroutine=true` (default), each request runs in a fresh coroutine — a single worker handles many concurrent requests.
- **Task workers** (`task_worker_num`): blocking workers for slow/CPU tasks. Opt into coroutines via `task_enable_coroutine`.
- **User processes**: added via `$server->addProcess(new Swoole\Process(...))`.

### Dispatch modes

| Mode | Constant | Use for |
|---|---|---|
| 1 | `SWOOLE_DISPATCH_ROUND` | Stateless HTTP, async only; `onConnect`/`onClose` suppressed |
| 2 | `SWOOLE_DISPATCH_FDMOD` | Stateful TCP (fd % worker_num) |
| 3 | `SWOOLE_DISPATCH_IDLE_WORKER` | Preemptive to idle worker — recommended for HTTP |
| 4 | `SWOOLE_DISPATCH_IPMOD` | Sticky by client IP |
| 5 | `SWOOLE_DISPATCH_UIDMOD` | Sticky by `$server->bind($fd, $uid)` |
| 7 | `SWOOLE_DISPATCH_STREAM` | Workers `accept` themselves; even load |
| 9 | `SWOOLE_DISPATCH_CO_REQ_LB` | Coroutine request LB (best for stateless HTTP) |

### Task workers

```php
$server->set([
    'task_worker_num'       => 4,
    'task_enable_coroutine' => true,
    'task_object'           => true,
]);

$server->on('request', function (Request $req, Response $resp) use ($server) {
    // Fire-and-forget
    $id = $server->task(['job' => 'resize', 'file' => $req->post['file']]);

    // With per-task finish callback
    $server->task(['x' => 1], -1, function ($server, $taskId, $result) {
        // runs in originating worker
    });

    // Concurrent batch (requires task_enable_coroutine=true)
    $results = $server->taskCo([['job' => 'a'], ['job' => 'b']], 5.0);

    $resp->end('queued');
});

$server->on('task', function (Server $server, Swoole\Server\Task $task) {
    $result = heavyWork($task->data);
    $task->finish($result);  // triggers onFinish in the dispatching worker
});

$server->on('finish', function (Server $server, int $taskId, $result): void {
    // runs in the dispatching event worker
});
```

**Caveat**: `task()`/`taskwait()` can only be called from event workers. Prefer `taskCo()` over `taskwait()` when `task_enable_coroutine=true`.

### `Swoole\WebSocket\Server`

```php
use Swoole\Http\Request;
use Swoole\WebSocket\Frame;
use Swoole\WebSocket\Server;

$server = new Server('0.0.0.0', 9501, SWOOLE_BASE);
$server->set([
    'worker_num'            => 4,
    'websocket_compression' => true,
]);

$server->on('open', function (Server $server, Request $request) {
    $server->push($request->fd, 'welcome');
});

$server->on('message', function (Server $server, Frame $frame) {
    $server->push(
        $frame->fd,
        $frame->data,
        SWOOLE_WEBSOCKET_OPCODE_TEXT,
        SWOOLE_WEBSOCKET_FLAG_FIN | SWOOLE_WEBSOCKET_FLAG_COMPRESS
    );
});

$server->on('close', function (Server $server, int $fd): void {});
$server->start();
```

WS-specific methods:

```php
public function push(int $fd, Frame|string $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = WEBSOCKET_FLAG_FIN): bool;
public function isEstablished(int $fd): bool;    // NOT exists() — use this for WS
public function disconnect(int $fd, int $code = WEBSOCKET_CLOSE_NORMAL, string $reason = ''): bool;
public function ping(int $fd, string $data = ''): bool;
```

**Broadcast pattern**:

```php
foreach ($server->connections as $fd) {
    if ($server->isEstablished($fd)) {
        $server->push($fd, $payload);
    }
}
```

**`onHandShake` vs `onOpen`**: if you define `onHandShake`, you take full control of the handshake (manual RFC6455 Accept, custom headers). `onOpen` fires automatically after the built-in handshake. Use `onOpen` unless you need to inspect/reject the upgrade.

### TCP server with packet framing

`open_length_check` + `package_length_type` etc. make `onReceive` deliver complete packets:

```php
$server = new Swoole\Server('0.0.0.0', 9504, SWOOLE_BASE);

$server->set([
    'open_length_check'     => true,
    'package_max_length'    => 8 * 1024 * 1024,
    'package_length_type'   => 'N',  // pack() format, big-endian uint32
    'package_length_offset' => 0,
    'package_body_offset'   => 4,
]);

$server->on('receive', function ($server, int $fd, int $reactorId, string $data) {
    // $data is a complete packet
    $server->send($fd, pack('N', strlen($data)) . $data);
});
```

EOF variant: `'open_eof_split' => true, 'package_eof' => "\r\n"`.

### Graceful reload

- `$server->reload()` → `SIGUSR1` to manager. Workers finish in-flight requests, exit, and get replaced.
- With `reload_async=true`, old workers keep running their current coroutines after being told to shut down. `onWorkerExit` fires when they're done — close DB handles there.
- `$server->reload(true)` reloads only task workers (`SIGUSR2`).
- `$server->shutdown()` → `SIGTERM` to master.
- `$server->stop($workerId)` → restart a single worker.

**Smooth reload only picks up files `require`'d inside `onWorkerStart`**. Files required before `$server->start()` stay cached forever. Put your autoloader + bootstrap inside `onWorkerStart` so deploys can reload.

If `opcache.validate_timestamps=0` (production default), add `opcache_reset()` at the top of `onWorkerStart` — otherwise reload picks up nothing.

---

## 6. `Swoole\Process` and `Swoole\Process\Pool`

### `Swoole\Process`

```php
final class Swoole\Process {
    public const PIPE_MASTER = 1;
    public const PIPE_WORKER = 2;
    public const PIPE_READ   = 3;
    public const PIPE_WRITE  = 4;

    public int $pipe;
    public int $pid;
    public int $id;

    public function __construct(
        callable $callback,
        bool $redirectStdinStdout = false,
        int $pipeType = SOCK_DGRAM,    // 0=none, 1=SOCK_STREAM, 2=SOCK_DGRAM
        bool $enableCoroutine = false
    );

    public function start(): bool|int;
    public function write(string $data): int|false;
    public function read(int $size = 8192): string|false;

    // Sysv message queue IPC
    public function useQueue(int $key = 0, int $mode = 2, int $capacity = -1): bool;
    public function push(string $data): bool;
    public function pop(int $size = 65536): string|false;

    public function exportSocket(): Swoole\Coroutine\Socket|false;
    public function name(string $name): bool;
    public function exit(int $exitCode = 0): void;

    public static function wait(bool $blocking = true): array|false;
    public static function signal(int $signalNo, ?callable $callback = null): bool;
    public static function kill(int $pid, int $signalNo = SIGTERM): bool;
    public static function daemon(bool $nochdir = true, bool $noclose = true, array $pipes = []): bool;
}
```

**`$enableCoroutine = true`** makes the callback run inside a coroutine scheduler, so you can call coroutine I/O directly without wrapping in `Co\run()`.

```php
$worker = new Swoole\Process(function (Swoole\Process $self): void {
    while (true) {
        $msg = $self->read();
        if ($msg === false || $msg === '') break;
        $self->write(strtoupper($msg));
    }
}, redirectStdinStdout: false, pipeType: SOCK_STREAM, enableCoroutine: true);

$pid = $worker->start();
$worker->write("hello\n");
echo $worker->read();
Swoole\Process::wait();
```

### Signal handling

`pcntl_signal()` doesn't work inside servers. Use `Swoole\Process::signal(SIGTERM, fn() => $server->shutdown())` — installs an async handler on the reactor. The idiomatic Ctrl+C hook in a Swoole server:

```php
Swoole\Process::signal(SIGTERM, fn() => $server->shutdown());
Swoole\Process::signal(SIGINT,  fn() => $server->shutdown());
```

### `Swoole\Process\Pool`

```php
final class Swoole\Process\Pool {
    public function __construct(
        int $workerNum,
        int $ipcType = SWOOLE_IPC_NONE,   // 0=none, 1=UNIXSOCK, 2=MSGQUEUE, 3=SOCKET
        int $msgqueueKey = 0,
        bool $enableCoroutine = false
    );
    public function set(array $settings): void;
    public function on(string $event, callable $cb): bool;
    public function getProcess(int $workerId = -1): Process|false;
    public function listen(string $host, int $port = 0, int $backlog = 2048): bool;
    public function write(string $data): bool;
    public function sendMessage(string $data, int $dstWorkerId): bool;
    public function start();  // null on success, false on failure
    public function stop(): void;
    public function shutdown(): bool;
}
```

Events: `WorkerStart`, `WorkerStop`, `Message`, `Start`, `Shutdown`.

```php
use Swoole\Process\Pool;

$pool = new Pool(4, SWOOLE_IPC_SOCKET);

$pool->on('WorkerStart', function (Pool $pool, int $workerId) {
    echo "worker {$workerId} up\n";
});

$pool->on('Message', function (Pool $pool, string $data) {
    // Length-prefix framed by default on SWOOLE_IPC_SOCKET
});

$pool->listen('127.0.0.1', 8089);
$pool->start();
```

**Pool vs Server**: `Pool` is a minimal supervised worker pool — bring your own protocol, no reactor, no `onConnect`/`onReceive`. Good for "run my callable across N processes with shared TCP socket". `Server` is a full reactor + protocol helpers + tasks + HTTP/WS.

---

## 7. Shared memory — `Table`, `Atomic`, `Lock`

### `Swoole\Table`

Mmap'd shared memory hash table — the only way to share state across Swoole workers. Per-row spinlocks + CAS for consistency. Rows are fixed-size (all columns allocated inline), declared upfront, `create()`'d once.

```php
final class Swoole\Table implements Iterator, Countable {
    public const TYPE_INT    = 1;
    public const TYPE_FLOAT  = 2;
    public const TYPE_STRING = 3;

    public function __construct(int $tableSize, float $conflictProportion = 0.2);
    public function column(string $name, int $type, int $size = 0): bool;
    public function create(): bool;
    public function destroy(): bool;

    public function set(string $key, array $value): bool;
    public function get(string $key, ?string $field = null): mixed;
    public function exists(string $key): bool;
    public function del(string $key): bool;
    public function count(): int;
    public function incr(string $key, string $column, int|float $incrby = 1): int|float;
    public function decr(string $key, string $column, int|float $incrby = 1): int|float;
    public function getSize(): int;         // max rows
    public function getMemorySize(): int;
    public function stats(): array|false;
}
```

**You MUST create the table before `$server->start()`** so workers inherit the mmap.

```php
$table = new Swoole\Table(8192);
$table->column('name',  Swoole\Table::TYPE_STRING, 64);
$table->column('age',   Swoole\Table::TYPE_INT);
$table->column('score', Swoole\Table::TYPE_FLOAT);
$table->create();

$server = new Swoole\Http\Server('0.0.0.0', 9501);
$server->table = $table;  // attach so workers can reach it

$server->on('request', function ($req, $resp) use ($table) {
    $id = $req->get['id'] ?? 'x';
    $table->set($id, ['name' => 'ada', 'age' => 37, 'score' => 99.5]);
    $resp->end(json_encode($table->get($id)));
});

$server->start();

foreach ($table as $key => $row) { /* $row is an assoc array */ }
```

**Gotchas**:
- `TYPE_STRING` columns have fixed byte length; overflow is silently truncated.
- Actual capacity ≈ `tableSize × (1 + conflictProportion)`; size ~30% over peak.
- In 5.0+, `Table` **no longer implements `ArrayAccess`** — use `set()`/`get()`, not `$table[$key]`.

### `Swoole\Atomic`, `Swoole\Atomic\Long`

Shared-memory counters. `Atomic` is unsigned 32-bit, `Atomic\Long` is signed 64-bit.

```php
class Swoole\Atomic {
    public function __construct(int $value = 0);
    public function add(int $n = 1): int;
    public function sub(int $n = 1): int;
    public function get(): int;
    public function set(int $value): void;
    public function cmpset(int $cmp, int $new): bool;
    public function wait(float $timeout = 1.0): bool;
    public function wakeup(int $count = 1): bool;
}
```

`wait()`/`wakeup()` are futex-style and **block the entire process** — don't use in server event workers, use `Channel` instead.

### `Swoole\Lock`

**Process-level lock, NOT coroutine-safe**. If a coroutine holds the lock and yields, another coroutine in the same worker will deadlock. Use a `Channel(1)` for coroutine-safe mutual exclusion.

In 6.1+, the API was unified to just `__construct`, `lock($operation = LOCK_EX, float $timeout = -1)`, `unlock()`. `lockwait()` and `trylock()` were removed — use `$lock->lock(LOCK_EX | LOCK_NB, $timeout)` (mirrors PHP `flock()`).

---

## 8. Coroutine clients

### `Swoole\Coroutine\Http\Client`

```php
new Swoole\Coroutine\Http\Client(string $host, int $port, bool $ssl = false)
```

`$host` = IP, domain (async DNS), or `unix://tmp/foo.sock`. Do NOT pass `http://` prefix. Requires `--enable-openssl` for `$ssl = true` (always on in 6.2+).

**Properties**: `$errCode`, `$errMsg`, `$statusCode` (negative = network issue), `$body`, `$headers`, `$cookies`, `$set_cookie_headers`.

Negative statusCode constants:
- `-1` `SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED`
- `-2` `SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT`
- `-3` `SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET`
- `-4` `SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED`

**Methods**:

```php
set(array $options): void
setMethod(string $method): void
setHeaders(array $headers): void
setCookies(array $cookies): void
setData(string|array $data): void
addFile(string $path, string $name, ?string $mime = null, ?string $filename = null, int $offset = 0, int $length = 0): void
addData(string $data, string $name, ?string $mime = null, ?string $filename = null): void
get(string $path): bool
post(string $path, mixed $data): bool
download(string $path, string $filename, int $offset = 0): bool
upgrade(string $path): bool        // websocket handshake
push(mixed $data, int $opcode = WEBSOCKET_OPCODE_TEXT, int $flags = WEBSOCKET_FLAG_FIN): bool
recv(float $timeout = 0): Frame|false
close(): bool
```

```php
use Swoole\Coroutine\Http\Client;
use function Swoole\Coroutine\run;

run(function () {
    $client = new Client('httpbin.org', 443, true);
    $client->set(['timeout' => 5.0, 'keep_alive' => true]);
    $client->setHeaders([
        'Host' => 'httpbin.org',
        'User-Agent' => 'app/1.0',
        'Accept-Encoding' => 'gzip',
    ]);

    $client->get('/get?hello=world');
    if ($client->statusCode < 0) {
        echo "network error: " . socket_strerror($client->errCode) . PHP_EOL;
    } else {
        echo $client->statusCode, PHP_EOL, $client->body, PHP_EOL;
    }

    $client->post('/post', ['name' => 'alice']);
    $client->close();
});
```

**Functional shortcuts** (`Swoole\Coroutine\Http` namespace, ≥ 4.6.4):

```php
use function Swoole\Coroutine\Http\{get, post, request};

$resp = get('https://httpbin.org/get?hello=world');
$data = json_decode($resp->getBody(), true);
```

### `Swoole\Coroutine\Http2\Client`

```php
use Swoole\Coroutine\Http2\Client;
use Swoole\Http2\Request;
use function Swoole\Coroutine\run;

run(function () {
    $client = new Client('api.example.com', 443, true);
    $client->set(['timeout' => 30, 'ssl_host_name' => 'api.example.com']);
    $client->connect();

    $req = new Request();
    $req->method  = 'POST';
    $req->path    = '/v1/events';
    $req->headers = ['host' => 'api.example.com', 'content-type' => 'application/json'];
    $req->data    = json_encode(['type' => 'click']);

    $streamId = $client->send($req);
    $response = $client->recv(5.0);
    $client->close();
});
```

For pipelined streams: set `$req->pipeline = true`, send it, then write chunks with `$client->write($streamId, $chunk, end: false)` until the final chunk with `end: true`.

### Removed coroutine clients (Swoole 6.0+)

**Removed entirely in 6.0.0**:
- `Swoole\Coroutine\MySQL` — use PDO/mysqli with `SWOOLE_HOOK_ALL`
- `Swoole\Coroutine\Redis` — use ext-redis with `SWOOLE_HOOK_ALL`
- `Swoole\Coroutine\PostgreSQL` — use pdo_pgsql with `SWOOLE_HOOK_PDO_PGSQL`

Never generate code using these in new projects targeting 6.x.

### Hooked PDO

With `SWOOLE_HOOK_ALL`, native `PDO` connections become coroutine-aware.

**One-connection-per-coroutine rule** — a single PDO handle is **not safe** to share between concurrent coroutines. The PDO connection holds a single socket; interleaved use corrupts the wire protocol. Use `PDOPool` (see §9).

**Persistent connections are NOT compatible** — always set `PDO::ATTR_PERSISTENT => false` (the default).

**SQLite caveat**: `pdo_sqlite` hook requires SQLite in serialized/multi-threaded mode. Since 6.1.5, `PDO::sqliteCreateAggregate/Collation/Function` are **removed in coroutine mode** (they called PHP callbacks from SQLite's C engine, which only works single-threaded).

```php
Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

Co\run(function () {
    $pdo = new PDO('mysql:host=127.0.0.1;dbname=app;charset=utf8mb4', 'app', 'secret', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = :id');
    $stmt->execute(['id' => 1]);
    var_dump($stmt->fetch());
});
```

### Hooked curl

**Use `SWOOLE_HOOK_NATIVE_CURL`** (included in `SWOOLE_HOOK_ALL`), not the legacy `SWOOLE_HOOK_CURL`. Real libcurl coroutinized — **Guzzle and Symfony HttpClient work transparently**.

```php
Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

Co\run(function () {
    $guzzle = new \GuzzleHttp\Client();
    $resp   = $guzzle->get('https://httpbin.org/get');
});
```

### `Swoole\Coroutine\Socket`

Low-level coroutine-native socket. Use for custom protocols the higher-level clients don't fit.

```php
new Swoole\Coroutine\Socket(int $domain, int $type, int $protocol);
// e.g. new Co\Socket(AF_INET, SOCK_STREAM, IPPROTO_TCP)
```

```php
bind(string $address, int $port = 0): bool
listen(int $backlog = 0): bool
accept(float $timeout = 0): Co\Socket|false
connect(string $host, int $port = 0, float $timeout = 0): bool
send(string $data, float $timeout = 0): int|false
sendAll(string $data, float $timeout = 0): int|false
recv(int $length = 65536, float $timeout = 0): string|false
recvAll(int $length, float $timeout = 0): string|false
recvPacket(float $timeout = 0): string|false   // uses framing from setProtocol()
recvLine(int $length = 65536, float $timeout = 0): string|false
setProtocol(array $settings): bool              // same framing options as Server->set()
checkLiveness(): bool
close(): bool
```

---

## 9. Connection pooling — `swoole/library`

`swoole/library` ships with ext-swoole (auto-loaded via `swoole.enable_library=On`). Provides the standard pools.

### `Swoole\ConnectionPool` — base class

```php
class Swoole\ConnectionPool {
    public const DEFAULT_SIZE = 64;

    public function __construct(
        callable $constructor,
        int $size = self::DEFAULT_SIZE,
        protected ?string $proxy = null
    );
    public function fill(): void;
    public function get(float $timeout = -1): mixed;
    public function put($connection): void;
    public function close(): void;
}
```

- Lazy — connections only created on first `get()` when empty.
- `get(-1)` (default) blocks forever; `get($timeout)` returns `false` on pop timeout.
- `put(null)` signals a broken connection — pool decrements count and rebuilds next `get()`.
- No eviction policy.

### `Swoole\Database\PDOPool`

```php
class Swoole\Database\PDOPool extends Swoole\ConnectionPool {
    public function __construct(PDOConfig $config, int $size = self::DEFAULT_SIZE);
    public function get(float $timeout = -1): PDOProxy|false;
}
```

Supported drivers: `mysql`, `pgsql`, `oci`, `sqlite`. Each connection wrapped in `PDOProxy` which:
- Forces `PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION`
- Auto-reconnects on lost-connection exceptions outside transactions
- Tracks `inTransaction` count; resets stale transaction state on checkout

**SQLite restriction**: `PDOPool` rejects `:memory:` and empty DB names.

### `PDOConfig`

```php
$config = (new Swoole\Database\PDOConfig())
    ->withDriver('mysql')         // mysql | pgsql | oci | sqlite
    ->withHost('127.0.0.1')
    ->withPort(3306)
    ->withDbname('app')
    ->withCharset('utf8mb4')
    ->withUsername('app')
    ->withPassword('secret')
    ->withOptions([
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
```

### `RedisPool` / `RedisConfig`

Returns a native `\Redis` (phpredis), not a proxy.

```php
$pool = new Swoole\Database\RedisPool(
    (new Swoole\Database\RedisConfig())
        ->withHost('127.0.0.1')
        ->withPort(6379)
        ->withAuth('')
        ->withDbIndex(0)
        ->withTimeout(1.0)
        ->withOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_IGBINARY),
    64
);
```

`MysqliPool` / `MysqliConfig` follow the same shape.

### Canonical pattern — "put it back or leak"

**A pool connection that isn't returned is lost forever.** Wrap every checkout in `try/finally`:

```php
use Swoole\Database\{PDOConfig, PDOPool};
use function Swoole\Coroutine\run;

Co::set(['hook_flags' => SWOOLE_HOOK_ALL]);

run(function () {
    $pool = new PDOPool(
        (new PDOConfig())
            ->withDriver('mysql')
            ->withHost('127.0.0.1')
            ->withPort(3306)
            ->withDbname('app')
            ->withCharset('utf8mb4')
            ->withUsername('app')
            ->withPassword('secret')
            ->withOptions([PDO::ATTR_EMULATE_PREPARES => false]),
        size: 32,
    );

    for ($i = 0; $i < 100; $i++) {
        go(function () use ($pool, $i) {
            $pdo = $pool->get();
            try {
                $stmt = $pdo->prepare('SELECT id, email FROM users WHERE id = :id');
                $stmt->execute(['id' => $i + 1]);
                var_dump($stmt->fetch());
            } finally {
                $pool->put($pdo);  // MUST return, even on exception
            }
        });
    }
});
```

Or cleaner with `defer`:

```php
go(function () use ($pool) {
    $conn = $pool->get();
    Co::defer(fn() => $pool->put($conn));
    // ... use $conn ...
});
```

**Transactions**: must begin and commit on the **same connection**. Don't return a connection to the pool mid-transaction. `PDOPool::get()` calls `reset()` on checkout to clear stale state defensively.

### Channel-as-pool pattern (for clients without a built-in pool)

```php
use Swoole\Coroutine\Channel;

final class GrpcPool
{
    private Channel $channel;

    public function __construct(
        private readonly \Closure $factory,
        private readonly int $size = 32,
    ) {
        $this->channel = new Channel($size);
        for ($i = 0; $i < $size; $i++) {
            $this->channel->push(($this->factory)());
        }
    }

    public function get(float $timeout = -1): object
    {
        $conn = $this->channel->pop($timeout);
        if ($conn === false) {
            throw new \RuntimeException('pool exhausted or closed');
        }
        return $conn;
    }

    public function put(object $conn): void
    {
        $this->channel->push($conn);
    }
}
```

---

## 10. Pitfalls catalog

### Never block inside a coroutine

These are synchronous-blocking and stall the whole worker (not just one coroutine):
- `mysql`/`mysqli`/`pdo_mysql` **without** `mysqlnd + hooks`
- `sleep()` without `SWOOLE_HOOK_SLEEP`
- `curl_*` without `SWOOLE_HOOK_NATIVE_CURL`
- `file_*`/`fread`/`fwrite` without `SWOOLE_HOOK_FILE`
- `$server->taskwait()`, `$server->sendwait()` in sync mode
- Any extension that bypasses PHP streams

**Fix**: enable `SWOOLE_HOOK_ALL` at bootstrap. Exclude individual flags only when a specific library fights the scheduler (e.g. `Runtime::setHookFlags(SWOOLE_HOOK_ALL ^ SWOOLE_HOOK_TCP)` for PHPMailer's raw SMTP sockets).

### Sharing a connection across coroutines

Swoole's own error string is literal: `"redis client has already been bound to another coroutine"`. A `PDO`, `Redis`, or socket handle is bound to the first coroutine that issues a call. **Always** use a pool, one checkout per coroutine.

**Multiple processes must not share connections either.** Create per-worker pools in `onWorkerStart`, not program-global. Pre-`start()` globals are copy-on-write shared memory and diverge on first write.

### `pcntl_*` is forbidden

`pcntl_fork`, `pcntl_signal`, `pcntl_waitpid` interact badly with Swoole's `signalfd`-based signal handling. Use `Swoole\Process`, `Swoole\Process::signal()`, and `Swoole\Coroutine\System::waitSignal()`.

### `go()` in `onStart` / `onManagerStart`

`onStart` runs in the master process — "only allows echo, logging, and process renaming". Modern Swoole lets you create coroutines there, but coroutine APIs in `onStart` can conflict with `dispatch_func` and `package_length_func`. Idiomatic bootstrap goes in **`onWorkerStart`** (per worker), not `onStart`.

### Channel deadlocks

`Channel::pop()` with default `timeout = -1` hangs forever if the only producer has already exited and the channel is empty. **Always pass a timeout**, or pair producers with `$channel->close()`.

### Cooperative ≠ concurrent-safe

Coroutines yield at I/O points. Any read-modify-write across an I/O boundary is a data race. Example:

```php
// WRONG — yield can interleave
$count = $table->get('k', 'count');
$count++;                           // yield here is possible if anything I/O-ish runs
$table->set('k', ['count' => $count]);

// RIGHT — atomic
$table->incr('k', 'count');
```

### Exception handling across coroutines

You **cannot** catch exceptions across coroutines. `go(fn() => throw new X)` wrapped in an outer `try/catch` does nothing — the exception fires inside the child. Every top-level `go()` closure should begin with `try { ... } catch (\Throwable $e) { $logger->error($e); }`.

### Stashing `$this` in coroutine context

`Coroutine::getContext()['controller'] = $this` keeps the controller alive for the coroutine's lifetime — usually a leak. Stash only the data you need, not the controller.

### Framework compatibility

| Framework | Status |
|---|---|
| Hyperf | **Swoole-native**, production-ready |
| Webman | Fast, Swoole driver available |
| Imi | Swoole-native |
| Laravel Octane | Works, but bound singletons leak `$request`/DB connections. Use `app->scoped()` not `app->singleton()` for request-adjacent services. |
| Slim / Mezzio | Work via PSR-15 bridges, not Swoole-aware |
| Phalcon | **Incompatible** — C extension, globals-heavy |

**Never call `set_exception_handler()` for control flow** — must use `try/catch`.

---

## 11. Production tuning

All options from `$server->set([...])`.

### Worker sizing

- **`worker_num`**: default = `swoole_cpu_num()`. For async/coroutine: 1–4× cores. For fully sync: size to `(response_time × target_qps)`. Hard cap `SWOOLE_CPU_NUM * 1000`.
- **`task_worker_num`**: `(tasks_per_second / tasks_per_worker_per_second)`. Default 0.
- **`task_enable_coroutine`**: `true` to run task workers inside a coroutine scheduler.
- **`max_request`**: worker respawns after N requests. Mitigates slow leaks. **Only useful for sync-blocking, stateless request-response servers — in SWOOLE_BASE mode it has no effect.** Reasonable: 10,000–50,000. Setting to 1 is the "FPM-emulation anti-pattern" — throws away Swoole's benefits.
- **`max_wait_time`**: seconds workers have to drain in-flight requests on reload/shutdown. Set 30–60s for HTTP.
- **`reload_async`**: **set to `true`** for graceful reload with coroutines — kernel adds coroutine-count check before exit, enabling `onWorkerExit`.
- **`enable_coroutine`**: default `true` since 4.4+. Wraps every event callback in an implicit `go()`.
- **`hook_flags`**: server-level equivalent to `Runtime::enableCoroutine()`. Preferred.
- **`max_coroutine`**: per-worker cap, default 100000.

### Buffers

- **`package_max_length`**: max single packet bytes. Default 2 MB. HTTP POST body limit.
- **`buffer_output_size`**: max single `send()` payload. Default 2 MB. Memory cost = `worker_num × buffer_output_size`.
- **`socket_buffer_size`**: per-connection send buffer. Default 2 MB.

### Dispatch mode

- **Stateless async HTTP**: `SWOOLE_DISPATCH_IDLE_WORKER` (3) or `SWOOLE_DISPATCH_CO_REQ_LB` (9)
- **Stateless sync**: mode 3
- **Stateful TCP**: mode 2, 4, or 5

Modes 1 and 3 suppress `onConnect`/`onClose`.

### SSL / HTTP/2 / compression

- **`ssl_cert_file`**, **`ssl_key_file`**: PEM only. Set `SWOOLE_SSL` socket flag: `new Swoole\Http\Server($host, $port, SWOOLE_PROCESS, SWOOLE_SOCK_TCP | SWOOLE_SSL)`.
- **`open_http2_protocol`**: requires `--enable-http2`.
- **`http_compression`**: gzip/brotli/zstd (6.0+ added zstd).

### Daemon / logs

- **`daemonize`**: set **`false`** under systemd/supervisord/Docker/k8s. Only `true` from interactive shell.
- **`log_file`**: use **absolute paths** — CWD changes after daemonize.
- **`log_level`**: `SWOOLE_LOG_DEBUG` (0) → `SWOOLE_LOG_ERROR` (5). `SIGRTMIN` reopens the log after logrotate.
- **`log_rotation`**: `SWOOLE_LOG_ROTATION_DAILY|HOURLY|MONTHLY` (4.5.2+).

### Kernel tuning (Linux)

```
ulimit -n 262140                                  # /etc/security/limits.conf
net.core.somaxconn = 65535                        # sysctl
net.ipv4.tcp_tw_reuse = 1
net.ipv4.tcp_max_syn_backlog = 81920
net.core.wmem_max = 16777216
net.core.rmem_max = 16777216
net.unix.max_dgram_qlen = 100                     # Swoole uses unix sockets for IPC
```

Do **not** set `net.ipv4.tcp_tw_recycle = 1` — removed in Linux 4.12 (was always unsafe with NAT).

---

## 12. Debugging

### Live introspection

```php
Swoole\Coroutine::stats();
// ['coroutine_num' => 132, 'coroutine_peak_num' => 213, 'c_stack_size' => 2097152, ...]

foreach (Swoole\Coroutine::list() as $cid) {
    $frame = Swoole\Coroutine::getBackTrace($cid);  // same format as debug_backtrace()
}

Swoole\Coroutine::printBackTrace();  // current coroutine

$server->stats();
// connection_num, accept_count, close_count, tasking_num, request_count,
// worker_request_count, worker_dispatch_count, task_queue_num, coroutine_num
```

Expose these on an internal admin port. `worker_dispatch_count` minus `worker_request_count` tells you if the master is shoveling faster than the worker drains.

### Xdebug gap

**Xdebug is incompatible with Swoole coroutines** on all current versions. Also incompatible: phptrace, aop, molten, xhprof, phalcon. Options:

1. Run the same routes via `php-fpm`/`php -S` (PSR-15 adapter) for interactive debugging, then switch to Swoole for integration/load.
2. **`Coroutine::getBackTrace` + structured logging** at interesting points.
3. **GDB with the Swoole gdbinit**: `(gdb) source /path/to/swoole-src/gdbinit` then `co_list`, `co_bt`, `co_status`.
4. `strace -f -p <worker_pid>` for syscall-level visibility.

### Voluntary yield for fairness

`Coroutine::sleep(0)` or `Coroutine::yield()` — hand control back to the scheduler without sleeping. Useful inside CPU-heavy loops so other coroutines can progress.

---

## 13. Testing

### PHPUnit entry point

```php
public function testAsync(): void
{
    $result = null;
    \Swoole\Coroutine\run(function () use (&$result) {
        $client = new \Swoole\Coroutine\Http\Client('httpbin.org', 443, true);
        $client->get('/ip');
        $result = $client->body;
    });
    self::assertNotNull($result);
}
```

`Coroutine\run()` creates a scheduler, runs the closure as root, and blocks until all spawned coroutines finish. Wrapping with `go()` outside a scheduler prints a warning and returns immediately.

### Resetting state between tests

Workers are resident — static state persists across tests. In `setUp()`:
- Reset any singleton
- Let each test run in its own root coroutine (fresh context)
- `Swoole\Timer::clearAll()` if your code registers tickers

In `tearDown()` assert `Swoole\Coroutine::stats()['coroutine_num'] === 0` — non-zero means a leak.

### Autocompletion / static analysis

```bash
composer require --dev swoole/ide-helper
```

Provides stubs for every `Swoole\*` symbol. `phpstan.neon`:

```yaml
parameters:
    bootstrapFiles:
        - vendor/swoole/ide-helper/src/swoole/constants.php
```

PHPStan can reach level 8 on Swoole code with the stubs loaded.

---

## 14. Swoole 6.x version notes

### 6.0 (2024-12)

- **Removed**: `Coroutine\MySQL`, `Coroutine\Redis`, `Coroutine\PostgreSQL`. Use hooked PDO instead.
- Requires PHP 8.1+ (PHP 8.0 dropped).
- **Multi-threading mode** shipped: `SWOOLE_THREAD`, `Swoole\Thread`, `Thread\Map/ArrayList/Queue/Lock/Barrier/Atomic`. Requires ZTS PHP + `--enable-swoole-thread`.
- **io_uring** for file AIO: `--enable-iouring`.
- `Swoole\Async\Client` (new non-blocking TCP/UDP/Unix client).
- zstd HTTP compression.
- Non-blocking reentrant coroutine mutex.

### 6.1 (2025-10)

- **llhttp** replaces `http_parser` as default HTTP parser.
- **Lock API unified**: `__construct`, `lock($op = LOCK_EX, $timeout = -1)`, `unlock()`. `lockwait()` and `trylock()` **removed**. Use `$lock->lock(LOCK_EX | LOCK_NB, $timeout)`.
- **stdext** module (opt-in via `--enable-swoole-stdext`): OOP on basic types.
- **Coroutine cancellation** gains `$throwException` parameter → throws `Swoole\Coroutine\CanceledException`.
- WebSocket: `disconnect()`, `ping()`, fragmented message support on both client and server.
- Runtime hooks can **only** be set in the main thread, before any child threads.
- 6.1.2: `async.file://` stream wrapper for per-call async file I/O.
- 6.1.5: `PDO::sqliteCreateAggregate/Collation/Function` removed from coroutine mode.
- 6.1.6: auto-strips `SWOOLE_HOOK_SOCKETS` if ext-sockets not loaded.
- 6.1.7: hooked `pdo_pgsql` gained timeout support.

### 6.2 (2026-04)

- Requires **PHP 8.2+** (PHP 8.1 dropped). Supports up to PHP 8.5.
- Coroutine **FTP client** (`--enable-swoole-ftp`).
- Coroutine **SSH client** (`--with-swoole-ssh2`).
- Coroutine **`pdo_firebird`** + `SWOOLE_HOOK_PDO_FIREBIRD`.
- **`SWOOLE_HOOK_MONGODB`** via `Swoole\RemoteObject\Server` (transparent MongoDB).
- **`SWOOLE_HOOK_NET_FUNCTION`** + coroutine `gethostbyname()`.
- **`Swoole\Coroutine::setTimeLimit()`** — per-coroutine execution timeout.
- HTTP coroutine server over io_uring sockets: `--enable-uring-socket`.
- Static file server URL rewriting.
- `--enable-openssl` configure flag **removed** (always on).
- `liburing >= 2.8` required.
- `Coroutine::cancel()` cancels in-flight io_uring ops.

### Build flags cheatsheet

```bash
./configure --enable-swoole \
  --enable-sockets \
  --enable-swoole-thread \       # requires ZTS
  --enable-swoole-curl \          # SWOOLE_HOOK_NATIVE_CURL
  --enable-swoole-pgsql \         # coroutine pgsql
  --enable-swoole-sqlite \        # coroutine sqlite
  --with-swoole-firebird \        # 6.2+
  --with-swoole-ssh2 \            # 6.2+
  --enable-swoole-ftp \           # 6.2+
  --enable-iouring \              # Linux io_uring
  --enable-uring-socket \         # 6.2+ HTTP over io_uring
  --enable-brotli \
  --enable-zstd \                 # 6.0+
  --enable-cares                  # c-ares DNS
```

### swoole/library version alignment

`swoole/library` is bundled directly into ext-swoole builds and auto-loaded via `swoole.enable_library=On`. Its last tagged composer release was **v6.0.2 (2025-03-22)** — it has not been retagged for 6.1/6.2 despite master branch changes that ship inside the extension. **If you `composer require swoole/library`, you'll get v6.0.2.** Treat the extension as the source of truth for library classes in production.

---

## Quick reference — canonical program skeleton

```php
<?php
declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Coroutine\Barrier;
use Swoole\Coroutine\Channel;
use function Swoole\Coroutine\run;

Coroutine::set([
    'hook_flags'            => SWOOLE_HOOK_ALL,
    'max_coroutine'         => 10_000,
    'socket_timeout'        => 5,
    'enable_deadlock_check' => true,
]);

run(function (): void {
    $jobs    = new Channel(32);
    $results = new Channel(32);
    $barrier = Barrier::make();

    // Workers
    for ($w = 0; $w < 4; $w++) {
        go(function () use ($jobs, $results, $barrier): void {
            while (true) {
                $job = $jobs->pop();
                if ($job === false) return;
                $results->push(['job' => $job, 'pid' => getmypid()]);
            }
        });
    }

    // Producer
    go(function () use ($jobs, $barrier): void {
        foreach (range(1, 20) as $i) $jobs->push($i);
        $jobs->close();
    });

    // Collector
    go(function () use ($results, $barrier): void {
        defer(fn() => $results->close());
        $collected = [];
        for ($i = 0; $i < 20; $i++) {
            $msg = $results->pop(5.0);
            if ($msg === false) break;
            $collected[] = $msg;
        }
        var_dump($collected);
    });

    Barrier::wait($barrier);
});
```
