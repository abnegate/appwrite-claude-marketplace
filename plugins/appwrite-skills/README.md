# appwrite-skills

Slash commands and workflow rules tuned for Appwrite-stack work.

## Commands

### `/fanout <task>`

Decomposes a task into 3-5 independent research questions and dispatches
them to parallel `Explore` / `general-purpose` subagents in a single
message. Designed for repos where individual sessions run >$100 in PAYG
equivalent — fanning out to Haiku subagents front-loads the context gathering
and keeps the Opus parent window clean until the real edits start.

Use on: multi-file investigations, cross-repo tasks, feature work that
needs a design survey first. Skip for single-file edits with a known
target.

### `/swoole-audit [path]`

Scans a PHP + Swoole project for the bug classes that have repeatedly
bitten Appwrite cloud/edge:

- Pool exhaustion from shared static singletons
- `Co::set(['hook_flags' => ...])` / `enableCoroutine` called after pool
  init instead of before
- Redis / DB sockets shared across coroutines without pool wrapping
- Missing `use Swoole\Coroutine as Co;` imports
- `onWorkerStart` not reinitializing pools after fork

Outputs a P0/P1/P2 prioritized finding list with file:line references
and suggested fixes. Runs the six checks as parallel subagents for
speed.

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
   for read-only research subagents. Measured 45k Grep + 120k Read
   calls across recent sessions; shifting those to Haiku is the single
   biggest lever on session cost.
2. **Multi-repo work starts in plan mode** — any task touching more
   than one repo enters plan mode before the first edit. Prevents the
   `feat-dedicated-db`-style scenario where the same refactor gets
   rediscovered four times in four repos.
3. **Edit over Write, always** — `Write` is for new files only. Reinforces
   the measured 8:1 Edit:Write ratio that's working.

## Invocation

With the plugin installed:

```
/appwrite-skills:fanout fix the pool exhaustion in cloud/http.php
/appwrite-skills:swoole-audit ~/Local/cloud
/appwrite-skills:merge-conflict origin/main
```

The `CLAUDE.md` auto-loads whenever a session runs in a directory where
this plugin is active — no invocation needed.
