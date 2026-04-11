# appwrite-skills

Slash commands and workflow rules tuned for Appwrite-stack work.

## Commands

### `/fanout <task>`

Decomposes a task into 3-5 independent research questions and dispatches
them to parallel `Explore` / `general-purpose` subagents in a single
message. Designed for large Appwrite-stack repos where per-session
context accumulates quickly — fanning out to Haiku subagents front-loads
the context gathering and keeps the Opus parent window clean until the
real edits start.

Use on: multi-file investigations, cross-repo tasks, feature work that
needs a design survey first. Skip for single-file edits with a known
target.

### `/swoole-audit [path]`

Scans a PHP + Swoole project for 11 categories of Swoole-specific bug
class:

1. Runtime hooks and coroutine bootstrap ordering
2. Blocking I/O inside coroutines
3. Shared state across coroutines
4. Connection pools (construction, put-back, sizing, health)
5. Long-running process footguns (`exit`/`echo`/`header`/`session_start`)
6. Concurrency primitives (go exception loss, Lock in coroutines, …)
7. HTTP / WebSocket / TCP server config
8. Signal handling and shutdown
9. Shared memory (Table/Atomic/Lock)
10. Extensions and library incompatibility (Xdebug, APM, FPM frameworks)
11. Testing (Co\\run wrapper, state reset, ide-helper)

Outputs a P0/P1/P2 prioritized finding list with file:line references
and suggested fixes. Dispatches 11 parallel subagents (one per
category) for speed. Framework-agnostic — works on any Swoole project,
not just Appwrite.

### `/swoole-fix <audit-findings|path>`

Companion to `/swoole-audit`. Takes findings and dispatches fix
subagents in parallel with the `swoole-expert` skill loaded as
primary reference. Returns a diff per finding for human review.
Does NOT auto-apply.

### `/merge-conflict [base-branch]`

Resolves merge conflicts by analyzing the **intent** of each side rather
than the line-level diff. Classifies each conflicted file as:

- **Orthogonal** — independent changes, auto-merge
- **Overlapping** — related changes that compose, merge semantically
- **Contradictory** — genuine conflict, stop and ask the user

Dispatches per-file analysis as parallel subagents (with both histories,
the common ancestor, and the working tree state) and aggregates the
results. Auto-resolves orthogonal and overlapping files; always leaves
contradictory files for human review.

Defaults `$ARGUMENTS` to `origin/main` if empty.

## Workflow rules

The `CLAUDE.md` in this plugin encodes three rules that pay off on the
Appwrite-stack repos:

1. **Model routing** — Opus for the parent session doing edits, Haiku
   for read-only research subagents. Read-heavy exploration work
   (Grep / Glob / repeated Read passes) scales poorly with context
   size, so shifting it to cheaper subagents is the single biggest
   lever on session cost in large Appwrite-stack repos.
2. **Multi-repo work starts in plan mode** — any task touching more
   than one repo enters plan mode before the first edit. Cross-repo
   initiatives (dedicated-db, SDK regeneration, interface ports
   between cloud and edge) rediscover the same refactor in every
   affected repo if there's no plan.
3. **Edit over Write, always** — `Write` is for new files only.
   Iterative refinement via many small targeted Edits beats rare
   full rewrites on large, long-lived codebases.

## Invocation

With the plugin installed:

```
/appwrite-skills:fanout fix the pool exhaustion in cloud/http.php
/appwrite-skills:swoole-audit path/to/cloud
/appwrite-skills:merge-conflict origin/main
```

The `CLAUDE.md` auto-loads whenever a session runs in a directory where
this plugin is active — no invocation needed.
