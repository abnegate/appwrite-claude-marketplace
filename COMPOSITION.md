# Plugin composition

How the four plugins in this marketplace work together. The short
version: install all four for the full workflow, or mix and match
by layer.

```
┌─────────────────────────────────────────────────────────────────┐
│                       appwrite-conventions                     │
│  CLAUDE.md priors · utopia-patterns · swoole-expert (reference) │
└───────────────────────────────┬─────────────────────────────────┘
                                │  auto-loaded context
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                         utopia-experts                         │
│      50 per-library expert skills + utopia-router agent        │
│                 /utopia? for explicit routing                  │
└───────────────────────────────┬─────────────────────────────────┘
                                │  on-demand reference
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                         appwrite-skills                        │
│   /fanout · /swoole-audit · /swoole-fix · /merge-conflict      │
│                  /marketplace-help · CLAUDE.md                 │
└───────────────────────────────┬─────────────────────────────────┘
                                │  active workflow
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                         appwrite-hooks                         │
│    destructive · force-push · no-verify · conventional-commit  │
│  regression-test · format-lint · secrets (Edit/Write/Multi)    │
└─────────────────────────────────────────────────────────────────┘
```

Each layer depends only on the one above it (loosely — most plugins
are independent, but the compositions below make them work better
together).

## Layer 1 — priors (`appwrite-conventions`)

**What it is.** Static, auto-loaded context that every Appwrite-stack
session should start with. Utopia framework conventions, composer
rules, namespace layout, code style, testing discipline, Swoole
runtime model. Plus two reference skills (`utopia-patterns`, the
cross-cutting cheat sheet; `swoole-expert`, the 1,641-line Swoole
reference).

**When to install.** Always. This is the foundation every other
plugin assumes.

**What it depends on.** Nothing.

## Layer 2 — library knowledge (`utopia-experts`)

**What it is.** 50 per-library expert skills (one per `utopia-php`
library), a `utopia-router` agent that picks the right 1-3 skills
for a given question, and the `/utopia?` command for explicit
routing. Auto-generated `INDEX.md` catalogue with composition
notes for cross-library pairings.

**When to install.** When you're working in any Appwrite-stack
repo that imports `utopia-php/*` packages.

**What it depends on.** Nothing hard, but `appwrite-conventions`
provides the `utopia-patterns` overview that `utopia-router`
points at as the "big picture" fallback.

## Layer 3 — active workflow (`appwrite-skills`)

**What it is.** Slash commands for the work patterns that pay off:
parallel research fanout, Swoole static analysis, merge-conflict
resolution, and discovery. Plus three workflow rules in CLAUDE.md
(Opus-for-edits/Haiku-for-research, multi-repo tasks start in
plan mode, Edit over Write).

**When to install.** When you want the commands. Skip if you only
want discipline (hooks) or reference (conventions + experts).

**What it depends on.**
- `/swoole-audit` and `/swoole-fix` compose with the `swoole-expert`
  skill from `appwrite-conventions`. They work without it (degraded
  — fix rationales lose their citations), but the intended flow
  loads both.
- `/fanout` assumes you want parallel subagent dispatch as a
  workflow primitive; no hard dep on other plugins.

## Layer 4 — discipline (`appwrite-hooks`)

**What it is.** Seven PreToolUse hooks across Bash and
Edit/Write/MultiEdit that enforce the rules you wrote for yourself.
Conventional commits, regression tests for fixes, format+lint,
no-verify/amend blocker, force-push to protected branches,
destructive-shell guard, secret-file guard. All decisions logged
to `~/.claude/metrics/appwrite-hooks.jsonl`. Dry-run mode via
`APPWRITE_HOOKS_DRY_RUN=1`.

**When to install.** When you want the rules enforced automatically.
Safe to install alone.

**What it depends on.** Nothing. Pure enforcement layer.

## Typical setups

### Full stack (recommended)
```
/plugin install appwrite-hooks@appwrite-claude-marketplace
/plugin install appwrite-skills@appwrite-claude-marketplace
/plugin install appwrite-conventions@appwrite-claude-marketplace
/plugin install utopia-experts@appwrite-claude-marketplace
```
Every layer active. Hooks enforce discipline; conventions + experts
provide context; skills provide commands. This is what you want on
any Appwrite-stack daily driver.

### Rules only
```
/plugin install appwrite-hooks@appwrite-claude-marketplace
```
Just the hooks. Zero context impact, zero workflow changes. Good
for a team member who wants the force-push / secret / commit
discipline without the rest.

### Reference only
```
/plugin install appwrite-conventions@appwrite-claude-marketplace
/plugin install utopia-experts@appwrite-claude-marketplace
```
Context without commands. Useful in a read-mostly session where
you're exploring code and want the expert skills on tap but don't
want automatic enforcement getting in the way.

### Workflow without enforcement
```
/plugin install appwrite-skills@appwrite-claude-marketplace
/plugin install appwrite-conventions@appwrite-claude-marketplace
```
Commands + context, no hooks. Good for testing the plugins without
the discipline layer overriding your own workflow.

## Cross-plugin interactions

### `/swoole-audit` → `/swoole-fix` → `swoole-expert`
1. `/swoole-audit` (appwrite-skills) walks 11 bug categories,
   produces P0/P1/P2 findings.
2. User runs `/swoole-fix` (appwrite-skills) with the findings.
3. `/swoole-fix` loads the `swoole-expert` skill
   (appwrite-conventions) as primary reference for every fix
   pattern.
4. Fix diffs cite specific sections of `swoole-expert` so the
   human reviewer can verify the pattern.

### Any utopia question → `utopia-router` → 1-3 experts
1. Parent session has a question touching a utopia-php library.
2. Claude dispatches `utopia-router` (utopia-experts) via its
   description-based auto-routing, or the user invokes `/utopia?`.
3. Router reads `skills/INDEX.md`, picks 1-3 relevant experts
   based on the index's composition notes, reads them in its
   own Haiku context, and returns a distilled answer.
4. Parent session only sees the 200-400 word answer.

### Commit → hooks enforce conventions
1. User or Claude runs `git commit` via Bash.
2. `appwrite-hooks` runs 6 hooks in order: destructive guard,
   force-push guard, no-verify guard, conventional format,
   regression test (for `(fix):`), format+lint.
3. First failure blocks the commit with a specific error message.
4. Every decision is logged to
   `~/.claude/metrics/appwrite-hooks.jsonl` for later analysis.

### Edit a file → secrets guard
1. User or Claude runs Edit/Write/MultiEdit.
2. `secrets_guard_hook` checks the file path against secret
   patterns (`.env`, `*.pem`, `id_rsa`, etc.) and the content
   against secret regex patterns (AWS keys, private key blocks).
3. Allowlists `.env.example`, `test/`, `fixtures/`.
4. Block is per-call; override with
   `APPWRITE_HOOKS_ALLOW_SECRETS=1`.

## Development

Each plugin is self-contained under `plugins/<name>/`. Top-level
`scripts/validate_skills.py` walks all four plugins and asserts
frontmatter validity + name uniqueness across plugins. CI
(`.github/workflows/test.yml`) runs the hook test suite, the
validator, and a regeneration-idempotency check for the
`utopia-experts` INDEX.md on every PR.

See each plugin's README for the details of its own components.
See individual CHANGELOG.md files for version history.
