---
description: Fix findings from /swoole-audit using the swoole-expert skill as primary reference
argument-hint: "[audit-findings or path to re-audit]"
---

# /swoole-fix — Fix Swoole Audit Findings

Companion to `/swoole-audit`. Takes either a prior audit's finding
list (pasted into `$ARGUMENTS`) or a project path to re-audit, then
dispatches parallel fix subagents with the `swoole-expert` skill
loaded as primary reference. Returns a diff per finding for human
review — does NOT auto-apply.

## When to use

- You just ran `/swoole-audit` on a project and want the findings
  fixed. Pass the audit output as `$ARGUMENTS` to skip re-auditing.
- You know Swoole issues exist but haven't run the audit yet. Pass
  a path; this skill runs the audit first, then fixes.
- You only want to fix certain priority levels. Tell it explicitly
  ("fix only P0", "fix P0 and P1") or redact the other levels from
  the findings input.

## Execution

### Step 1: Gather findings

Branch on `$ARGUMENTS`:

- **Pasted findings** — the user passed audit output. Parse it to
  extract file:line + description + severity per finding. Expected
  shape matches `/swoole-audit` output.
- **Path to re-audit** — invoke `/swoole-audit` on the path first.
  Parse its output as above.
- **Empty** — assume the user wants to audit and fix the current
  directory. Run `/swoole-audit .`.

### Step 2: Load the swoole-expert skill

Read the full `swoole-expert` skill (at
`plugins/appwrite-conventions/skills/swoole-expert/SKILL.md`). The
audit categories map to sections in the expert skill; each fix
should cite the specific section it's following.

### Step 3: Classify findings by fix strategy

For each finding, pick one of:

- **Mechanical fix** — the fix is a one-line edit with a known
  pattern (add a hook flag, move a statement, add an import).
  Batch these into a single subagent that applies all mechanical
  fixes in one pass.
- **Structural fix** — the fix requires understanding surrounding
  code (moving pool init into `onWorkerStart`, wrapping a shared
  singleton in a pool, adding per-worker reinit). Each gets its
  own subagent so it can read surrounding context without
  context bleed between findings.
- **Uncertain** — the audit flagged a pattern but the right fix
  depends on intent the audit couldn't infer. Do NOT fix these
  automatically. List them at the end for the user to decide.

### Step 4: Dispatch fix subagents in parallel

For mechanical fixes: one subagent runs all of them in a single pass
(cheap Haiku).

For structural fixes: one subagent per finding (Opus or Sonnet),
each with:
- The specific finding (file:line + description)
- Enough code context to understand the change (100 lines around
  the finding, plus any related files the finding mentions)
- The `swoole-expert` section that covers the fix pattern
- Instructions to produce a diff, NOT apply it

All subagents dispatch in **one message** to maximise parallelism.

### Step 5: Aggregate and present

Collect all diffs. Present to the user in priority order (P0 first),
grouped by file, with a one-line rationale per fix linking back to
the `swoole-expert` section:

```
## Proposed fixes for /swoole-audit findings

### P0 — bin/http.php

Line 18 — Pool init before enableCoroutine
  See swoole-expert#runtime-hooks

```diff
- $pool = new Pool(/*...*/);
- Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
+ Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
+ $pool = new Pool(/*...*/);
```

Rationale: runtime hooks must be applied before any blocking I/O
primitive is constructed, or resources created beforehand will not
be hooked and will block the coroutine scheduler.

### P0 — src/Service/Cache.php
...
```

### Step 6: Do NOT apply

This skill is **read-only**. It proposes diffs; the user applies
them (possibly via `/fanout apply these fixes` as a follow-up).
The rationale: Swoole fixes are often subtle, and a bad fix to a
shared-state bug can make things worse. A human review loop is
cheap insurance.

## Hard rules

- Every diff must cite a `swoole-expert` section. If you can't
  cite a section, you shouldn't be proposing the fix — flag it as
  uncertain instead.
- Never fix more than 10 findings in one run. If the audit turned
  up 30 P0s, something systemic is wrong and a case-by-case fix
  pass isn't the right tool. Stop after 10 and tell the user.
- Mechanical and structural fixes go through different subagent
  types. Don't let a mechanical fix sprawl into a structural one.
- Uncertain findings are listed, not fixed. Be clear about why.
- Do not touch `tests/` — they often deliberately exercise the
  anti-patterns the audit flags.
- Do not touch `vendor/` — third-party code.

## Example

```
/swoole-fix P0 findings from the audit:
  bin/http.php:18 — PDO before Co::set
  src/Service/Cache.php:24 — Redis static singleton across coroutines
  src/Http/Handler.php:87 — go() body with no try/catch
```

Expected flow: parse 3 findings → classify (1 mechanical, 2
structural) → dispatch 1 batch subagent for bin/http.php + 2
focused subagents for Cache.php and Handler.php → aggregate 3
diffs with swoole-expert citations → present for review.

## See also

- `/swoole-audit` — produces the findings this skill consumes
- `swoole-expert` skill at
  `plugins/appwrite-conventions/skills/swoole-expert/SKILL.md` —
  primary reference for every fix pattern
