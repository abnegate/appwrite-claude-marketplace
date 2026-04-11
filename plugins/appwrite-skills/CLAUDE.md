# Appwrite Skills — Workflow Rules

These rules apply to any session where `appwrite-skills` is loaded. They
encode three workflow patterns that have been measured to pay off on the
Appwrite-stack repos (cloud, database, edge, sshoo, console, proxy).

## Model routing — Opus for edits, Haiku for research

Parent sessions that edit code run on Opus. Read-only research work runs
on Haiku subagents. This keeps the expensive parent context clean while
exploiting the cheap model for the work that scales poorly with context
size (repeated Grep/Glob/Read passes).

**How to apply:**
- When you need to answer a factual question about the codebase ("where
  is X defined", "how many call sites", "what does the current interface
  look like"), dispatch an `Explore` or `general-purpose` subagent with
  an explicit `model: "haiku"` override.
- Keep Opus for: planning, synthesis, actual edits, decisions.
- If a single question needs >5 Grep calls to answer, that's a clear
  signal to fan it out to a Haiku subagent rather than doing it inline.
- Never route a subagent to Opus unless the question genuinely requires
  reasoning a smaller model would fumble (e.g., cross-file architectural
  analysis).

## Multi-repo work starts in plan mode

Any task that touches more than one repository enters plan mode before
the first edit. The `feat-dedicated-db` initiative spanning cloud / edge
/ database / infrastructure is the canonical example — without a plan,
the same refactor gets rediscovered four times.

**How to apply:**
- Detect multi-repo scope by: mention of >1 repo name in the prompt,
  reference to a cross-repo branch name, or any task involving SDK
  regeneration / interface porting.
- Enter plan mode. Draft the sequence across all affected repos in one
  document.
- Only exit plan mode after the user has approved the plan.
- Single-repo tasks do not need plan mode unless the user asks for it.

## Edit over Write, always

Prefer `Edit` (surgical changes) over `Write` (full file replacement) by
a wide margin. This plugin's host has a measured 8:1 Edit:Write ratio —
that pattern is load-bearing for the iterative style that's working.

**How to apply:**
- `Write` is for new files only. If the file exists, use `Edit`.
- Never use `Write` to "clean up" an existing file. Run the lint/format
  tool instead.
- If you're about to `Write` an existing file because the number of
  edits is large, pause: split the changes into smaller Edits or spawn a
  subagent to refactor in steps.
- The exception: config files (`composer.json`, `.github/workflows/*`)
  that you're restructuring top-to-bottom. Even then, prefer Edit when
  possible.
