# Appwrite Skills — Workflow Rules

These rules apply to any session where `appwrite-skills` is loaded.
They encode three workflow patterns tuned for Appwrite-stack work,
where codebases are large, per-session context grows quickly, and most
tasks touch more than one repo.

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
the first edit. Cross-repo initiatives (dedicated-database work,
SDK regeneration cascades, interface ports between cloud and edge)
are common in the Appwrite stack — without a plan, the same refactor
gets rediscovered in every affected repo.

**How to apply:**
- Detect multi-repo scope by: mention of >1 repo name in the prompt,
  reference to a cross-repo branch name, or any task involving SDK
  regeneration / interface porting.
- Enter plan mode. Draft the sequence across all affected repos in one
  document.
- Only exit plan mode after the user has approved the plan.
- Single-repo tasks do not need plan mode unless the user asks for it.

## Edit over Write, always

Prefer `Edit` (surgical changes) over `Write` (full file replacement)
by a wide margin. Iterative refinement — many small targeted edits
instead of rare full rewrites — is the load-bearing pattern for
working on large, long-lived codebases like the Appwrite stack.

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
