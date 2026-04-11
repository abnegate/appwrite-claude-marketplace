---
name: utopia-console-expert
description: Expert reference for utopia-php/console — static helper for CLI output (colored log levels), prompts, subprocess execution with timeout, and a long-running daemon loop with automatic GC. Consult when building Appwrite bin workers or replacing ad-hoc echo/var_dump.
---

# utopia-php/console Expert

## Purpose
Static helper class for CLI output (colored log levels), user prompts, sub-process execution with timeout/progress callbacks, and a long-running daemon loop with automatic GC.

## Public API
- `Utopia\Console` — single static class (note: top-level `Utopia` namespace, not `Utopia\Console`)
- `Console::log/success/info/warning/error` — ANSI-colored stdout/stderr writes
- `Console::confirm(string $question): string` — blocking stdin read
- `Console::execute(array|string $cmd, string $stdin, string &$stdout, string &$stderr, int $timeout = -1, ?callable $onProgress = null): int`
- `Console::loop(callable $callback, int $sleep = 1, int $delay = 0, ?callable $onError = null): void`
- `Console::title(string)`, `Console::exit(int)`, `Console::isInteractive(): bool`

## Core patterns
- **Everything static** — no instance, no DI, no state. `use Utopia\Console;`
- **`execute()` wraps string commands** in `( cmd ) 3>/dev/null ; echo $? >&3` and uses a 4th pipe to capture the real exit code, since `proc_close` returns subshell wrapper codes. Non-blocking streams, 10ms usleep poll
- **`loop()` subtracts execution time from sleep window** (self-correcting cadence) and calls `gc_collect_cycles()` every 5 minutes to fight fragmentation in long-running workers
- **stdout vs stderr is stream-encoded**: warnings/errors go to STDERR, everything else to STDOUT — respects redirect conventions
- **`confirm()` returns empty string** (not throw) when not interactive — safe to call from daemon code paths

## Gotchas
- **`execute()` now has four args in the middle** (stdin, &stdout, &stderr, timeout). Older callers passing three string args (pre-stderr separation) will break — stderr used to be merged into stdout
- **`loop()` never returns in CLI mode** — `connection_aborted()` is always false, so the loop only exits via exception or signal. No built-in signal handler; wire `pcntl_signal` yourself if you need graceful shutdown
- **10ms usleep inside `execute()` busy-wait blocks the entire coroutine under Swoole** — wrap in `Co::wait` or run in a dedicated worker, or the whole server stalls
- **`confirm()` uses blocking `fgets`** on `php://stdin` — never call it from a Swoole HTTP worker

## Appwrite leverage opportunities
- **Replace ad-hoc `echo`/`var_dump` debug output** in `bin/*` workers (`worker-functions`, `worker-audits`, `realtime`) with the colored helpers — immediate readability win in `docker compose logs`
- **`Console::loop()` is the canonical pattern** for maintenance workers (stats rollups, TLS cert renewal, queue drainers) — it handles GC cycles which matter for hours-long Swoole processes
- **`execute()` with `onProgress` callback** is ideal for streaming `git clone`/`docker build` output from the Functions builder back to the UI via realtime channel — the callback fires on each chunk
- **In `migrate` and `doctor` commands**, use `execute()` with timeout to run `mysqldump`/`pg_dump` safely instead of `passthru()` which has no timeout

## Example
```php
use Utopia\Console;

Console::title('appwrite-worker-usage');
Console::info('Worker started');

Console::loop(function () {
    $stdout = '';
    $stderr = '';
    $code = Console::execute('clickhouse-client --query "OPTIMIZE TABLE stats"', '', $stdout, $stderr, 30);
    if ($code !== 0) {
        Console::error("optimize failed: {$stderr}");
        return;
    }
    Console::success('tick');
}, sleep: 60, onError: fn ($e) => Console::error($e->getMessage()));
```
