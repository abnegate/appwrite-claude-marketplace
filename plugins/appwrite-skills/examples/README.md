# appwrite-skills examples

Sample invocations of each slash command in this plugin, with the
shape of the expected output.

## `/fanout` — parallel research dispatch

**Input:**
```
/fanout fix the Swoole pool exhaustion that keeps biting on the cloud API pods
```

**Decomposition (dispatched as 5 parallel subagents in one message):**

1. Explore: "Find every Swoole pool instantiation in ~/Local/cloud —
   which entry points (http, cli, worker) create which pools, and in
   what order relative to enableCoroutine?"
2. Explore: "Where is SwoolePool imported and what's the difference
   between SwoolePool and plain Swoole\\Coroutine\\Channel usage in
   this repo?"
3. Explore: "Which recent commits (last 2 weeks) touched pool
   initialization? Summarize subjects + file changes."
4. Explore: "Are there any shared pool singletons surviving across
   worker forks? Look for static properties + `Co::set` placement."
5. general-purpose: "Check the Swoole 6 docs for `Co::set(['hook_flags' => SWOOLE_HOOK_ALL])`
   placement requirements — must it precede pool instantiation?"

**Expected flow:** parent dispatches all 5 in a single Agent tool
call message → waits → synthesises findings into a 3-8 bullet plan
→ presents to user before editing.

## `/swoole-audit` — Swoole correctness audit

**Input:**
```
/swoole-audit ~/Local/cloud
```

**Output shape:**
```
## Swoole audit — ~/Local/cloud

Entry points found (3)
  bin/http.php:12    — Swoole\Http\Server
  bin/websocket.php:8 — Swoole\WebSocket\Server
  bin/worker.php:15  — Swoole\Server (TCP)

P0 findings (4)
  bin/http.php:18    — PDO constructed before Co::set hook_flags.
                       Move Runtime::enableCoroutine to line 1.
  src/Service/Cache.php:24  — Redis handle stored in static $client,
                               reused across coroutines. Wrap in a pool.
  src/Http/Handler.php:87   — go body with no try/catch; exceptions
                               silently lost.
  src/Http/Handler.php:142  — exit(1) inside request handler kills
                               the worker.

P1 findings (3)
  ...

P2 findings (2)
  ...

Categories skipped:
  - Signal handling (no custom signal listener)
  - Task workers (task_worker_num = 0)
```

Dispatches 11 parallel subagents (one per audit category). Each
subagent reports under 250 words with file:line for every finding.
Parent synthesises into the above report.

## `/swoole-fix` — fix audit findings

**Input (with audit output):**
```
/swoole-fix
  bin/http.php:18 — PDO before Co::set
  src/Service/Cache.php:24 — Redis static singleton across coroutines
  src/Http/Handler.php:87 — go() body with no try/catch
```

**Expected flow:** classifies the 3 findings (1 mechanical, 2
structural) → dispatches 1 batch subagent for mechanical +
2 focused subagents for the structural fixes → loads
`swoole-expert` skill as primary reference → returns 3 diffs with
section citations → does NOT apply.

**Output shape:**
```
## Proposed fixes for /swoole-audit findings

### P0 — bin/http.php

Line 18 — Pool init before enableCoroutine
  See swoole-expert#runtime-hooks-and-coroutine-bootstrap

```diff
- $pool = new Pool(/*...*/);
- Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
+ Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
+ $pool = new Pool(/*...*/);
```

### P0 — src/Service/Cache.php
...
```

## `/merge-conflict` — intent-aware resolver

**Input:**
```
/merge-conflict origin/main
```
Assumes merge is already in progress (or starts one against
`origin/main`).

**Expected flow:** enumerate conflicted files → for each, dispatch
a subagent with both sides' history, common ancestor, and working
tree state → classify as orthogonal / overlapping / contradictory
→ auto-resolve orthogonal + overlapping → list contradictory for
human review.

**Output shape:**
```
## Conflict resolution — merging origin/main into feat-dedicated-db

Auto-resolved (7 files):
  src/Cache/Swoole.php     — orthogonal: import added + method renamed
  src/HTTP/Router.php      — overlapping: both added middleware, merged
  ... (5 more)

Needs review (2 files):
  src/Appwrite/Database.php — ours renames bar() to baz(),
                               theirs changes bar() signature
  src/Utopia/Queue.php     — ours drops deprecated field,
                              theirs adds validation to it

To finalize: resolve the 2 files above, git add, then git commit.
```

## `/marketplace-help` — discovery

**Input:**
```
/marketplace-help swoole
```

**Output:** filtered listing of everything matching "swoole" —
`swoole-expert` skill, `/swoole-audit`, `/swoole-fix`, and their
parent plugin headers. Other plugins omitted entirely.

Without a filter, shows the full marketplace contents grouped by
plugin.
