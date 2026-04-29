# appwrite-claude-marketplace

[![CI](https://github.com/abnegate/appwrite-claude-marketplace/actions/workflows/test.yml/badge.svg)](https://github.com/abnegate/appwrite-claude-marketplace/actions/workflows/test.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

AI coding-agent plugins tuned for the Appwrite / Utopia / Swoole stack.
Works with **Claude Code** and **OpenAI Codex CLI** (`AGENTS.md` symlinks
to `CLAUDE.md` — same file, zero duplication).

Five independent plugins. Install any combination.

| Plugin | What it adds to a Claude Code session |
|---|---|
| **appwrite-hooks** | 7 `PreToolUse` guards that block destructive shell commands, force-pushes to protected branches, conflict markers / TEMP code, non-conventional commits, `(fix):` commits without tests, lint failures, and writes to secret-bearing files. |
| **appwrite-skills** | 5 slash commands (`/fanout`, `/swoole-audit`, `/swoole-fix`, `/merge-conflict`, `/marketplace-help`) plus a small `CLAUDE.md` fragment with model-routing rules. |
| **appwrite-conventions** | Auto-loaded `CLAUDE.md` priors for backend / frontend / infra plus two on-demand reference skills (`utopia-patterns`, `swoole-expert`). |
| **utopia-experts** | 50 per-library expert skills covering the entire `utopia-php` ecosystem, plus a `utopia-router` agent that picks 1-3 relevant ones for any given question. |
| **appwrite-experts** | 11 per-service expert skills (auth, databases, functions, storage, messaging, teams, realtime, workers, tasks, kubernetes, cloud) plus an `appwrite-router` agent. Encodes file locations, patterns, and gotchas for the `appwrite/appwrite` and `appwrite/cloud` codebases. |

**122 PHPUnit tests across 4 suites · 14 evals + 48 trigger queries · 3-job CI · 3 weekly self-improvement workflows.**

---

## How each plugin works

### appwrite-hooks — `PreToolUse` guards

Claude Code's hook system runs your script **before** the agent calls a
tool, with the call's JSON payload on stdin. Your script's exit code
decides what happens next:

```
exit 0  → allow the tool call (stderr discarded)
exit 2  → block the call; stderr text is surfaced to Claude
other   → fail open (allow), so a broken hook never wedges the agent
```

`hooks.json` registers each script under a tool **matcher** (`Bash`,
`Edit|Write|MultiEdit`, …). Claude Code runs every matching hook
sequentially; the first non-zero exit blocks.

#### The seven guards

| Hook | Matcher | What triggers a block | Override |
|---|---|---|---|
| `destructive_guard` | Bash | `rm -rf` against `/`, `~`, `/etc`, `/Users`, glob-at-root, variable expansion. Strips `sudo` / `env` / `KEY=VALUE` prefixes; walks `&&` / `\|\|` / `;` / `\|` segments. Allows `node_modules`, `dist`, `/tmp/…`, etc. | `APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1` |
| `force_push_guard` | Bash | `--force` / `-f` / `--force-with-lease` / `+refspec` targeting `main`, `master`, `trunk`, `develop`, `N.N.x`, `release/*`, `hotfix/*`. Catches `--force-with-lease=…` equals form and combined short flags (`-uf`). | `APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1` |
| `staged_diff_scan` | Bash | Runs once per `git commit`. Scans `git diff --cached` for conflict markers (`<<<<<<<` / `=======` / `>>>>>>>`, no override) and TEMP/HACK/FIXME/XXX/`DO NOT MERGE` markers + `console.log` in JS/TS/Svelte (overridable). | `APPWRITE_HOOKS_ALLOW_TEMP_CODE=1` (temp markers only) |
| `conventional_commit` | Bash | Commit messages not matching `(type): subject` for the allowed type set; `--no-verify` and `--amend` flags. | `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1` (flags only) |
| `regression_test` | Bash | `(fix):` commits with no staged file matching test conventions (`tests/`, `*Test.php`, `*_test.go`, `*.spec.ts`, `__tests__/`, …). | `APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1` |
| `format_lint` | Bash | Detects ecosystem from staged extensions and runs the matching tool: `composer lint` (PHP), `./gradlew ktlintCheck` or `ktlint` (Kotlin), `cargo fmt --check && cargo clippy -D warnings` (Rust), `npx prettier --check .` (JS/TS), `ruff check .` (Python). Tool not on `PATH` → skipped, never blocks. | `APPWRITE_HOOKS_SKIP_LINT=1` |
| `secrets_guard` | Edit\|Write\|MultiEdit | Path patterns: `.env*` (but not `.env.example`), `credentials*`, `*.pem`/`.key`/`.p12`/`.pfx`/`.jks`/`.keystore`, `id_rsa`/`id_ed25519`/`id_ecdsa`/`id_dsa`, `kubeconfig*`, `*.token`, `.netrc`, `.pgpass`, `.aws/credentials`. Content patterns: AWS access keys, OpenAI keys, GitHub PATs, Slack bot tokens, private-key blocks, Mongo/Postgres URIs with credentials. Allowlists `fixtures/`, `test/`, `*.example`. | `APPWRITE_HOOKS_ALLOW_SECRETS=1` |

#### Cross-cutting protocol

All hooks share `_shared.php` (namespace `Marketplace\Hook\Shared`):

- **Dry-run** — `APPWRITE_HOOKS_DRY_RUN=1` flips every block into an
  `allow` with a `[DRY RUN — would block]` stderr message. Use it to
  audit "what would these hooks catch on my current branch?" without
  actually blocking.
- **Metrics** — every decision (`allowed` / `blocked` / `would-block` /
  `skipped`) appends a JSONL line to `~/.claude/metrics/appwrite-hooks.jsonl`
  with timestamp, hook name, tool name, verdict, and a short reason.
  Disable with `APPWRITE_HOOKS_NO_METRICS=1`.
- **No deps** — hooks are self-contained PHP 8.3+ scripts that
  `require_once __DIR__ . '/_shared.php'`. They do **not** load
  Composer's autoloader, so they work in a fresh plugin install
  without `composer install`.

### appwrite-skills — slash commands + workflow rules

A slash command in Claude Code is just a Markdown file under
`commands/<name>.md`. Frontmatter sets the `description` (shown in
`/help`); the body is the prompt the agent runs when you type `/<name>`.

| Command | What it does |
|---|---|
| `/fanout <task>` | Decomposes a task into 3-5 parallel **read-only** research subagents (Haiku) before any edits, so the parent (Opus) keeps a clean context window. |
| `/swoole-audit [path]` | Scans a PHP+Swoole project against 11 categories of known pitfalls (runtime hooks, blocking I/O, shared state, pools, signals, etc.) and outputs P0/P1/P2 findings citing the `swoole-expert` skill. |
| `/swoole-fix [findings]` | Reads `/swoole-audit` output, classifies each finding as mechanical / structural / uncertain, dispatches parallel fix agents for the first two, and returns diffs for review. |
| `/merge-conflict [branch]` | Resolves a merge by reading both sides' intent. Files classified orthogonal/overlapping/contradictory; first two are auto-resolved, third is left for human. |
| `/marketplace-help [filter]` | One-line description of every command / skill / agent / hook across all installed plugins, sourced from frontmatter. Optional substring filter. |

The plugin's `CLAUDE.md` adds three durable workflow rules to every
session: (1) Opus parents edit code, Haiku subagents do research; (2)
multi-repo tasks enter plan mode before the first edit; (3) prefer
`Edit` over `Write` for existing files.

### appwrite-conventions — auto-loaded priors

Claude Code automatically loads `CLAUDE.md` from any installed plugin
into the system prompt at session start. This plugin's `CLAUDE.md` is
the project's framework doctrine: which framework to use (Utopia, not
Laravel), Swoole 6 runtime expectations, namespace layout, REST
conventions, frontend tooling (SvelteKit + bun + Pink), Terraform / DO /
Cloudflare / Kubernetes constraints, and recurring bug patterns
distilled from analysing 600+ historical fix commits.

It also ships two on-demand reference skills that load only when the
agent decides they're relevant:

| Skill | When it triggers |
|---|---|
| `utopia-patterns` | Cross-cutting cheat sheet for Utopia PHP idioms — composer constraints, hook signatures, container wiring patterns. |
| `swoole-expert` | Deep production reference for Swoole 5.x/6.x. Primary citation source for `/swoole-audit` and `/swoole-fix`. |

### utopia-experts — 50 expert skills + a routing agent

Skills in Claude Code are Markdown files at `skills/<name>/SKILL.md`.
Each has YAML frontmatter (`name`, `description`); the body is loaded
into context when the agent decides the skill matches the current
question. Description quality is what drives trigger accuracy — the
agent reads only the description to decide whether to load the body.

This plugin ships one expert skill per public `utopia-php` library,
grouped into ten categories:

| Category | Skills |
|---|---|
| Framework core | http, di, servers, platform, config |
| Data layer | database, mongo, query, pools, dsn |
| Storage & I/O | storage, cache, fetch, compression, migration |
| Auth & security | auth, jwt, abuse, waf, validators |
| Runtime & system | cli, system, orchestration, preloader, proxy |
| Observability | logger, telemetry, audit, analytics, span |
| Messaging & async | messaging, queue, websocket, async, emails |
| Domain logic | pay, vcs, domains, dns, locale |
| Utilities | ab, registry, detector, image, agents |
| Misc | console, cloudevents, clickhouse, balancer, usage |

#### How the router works

`agents/utopia-router.md` is a Claude Code subagent. When you call it
(or it's auto-dispatched on a multi-library question), it:

1. Reads `skills/INDEX.md` — the auto-generated catalogue with every
   skill's name, description, and category, plus seven canonical
   multi-skill **pairings** (e.g. "observability pipeline" =
   `span` + `logger` + `telemetry` + `audit` + `analytics`).
2. Picks 1-3 skills (or a full pairing) for the question.
3. Loads each `SKILL.md` body and synthesises an answer from them.

This keeps the parent context clean — the router pulls in only the
relevant 5-10 KB instead of the full 50-skill corpus (~500 KB).

`INDEX.md` is regenerated by `bin/marketplace index` (see below) so it
always matches the on-disk skills. CI fails the build if it drifts.

---

## Maintenance CLI — `bin/marketplace`

A single PHP 8.3+ binary built on `utopia-php/cli` and `utopia-php/console`,
with three tasks. Boolean params follow the Utopia convention:
`--name=true` / `--name=false`.

```bash
composer install                                   # one-time setup
bin/marketplace                                    # task list
bin/marketplace sync                               # text drift report
bin/marketplace sync --json=true                   # machine-readable
bin/marketplace sync --regenerate-index=true       # also rerun the indexer
bin/marketplace sync --fail-on-drift=true          # exit 2 if any drift (CI)
bin/marketplace validate                           # frontmatter + manifests + evals
bin/marketplace index                              # regenerate utopia-experts INDEX.md
```

### `sync` — drift detection

`src/Sync/` (`Application` → `GhClient` → `Detector` → `*Report` →
`TextReporter` / JSON encode):

1. **Fetch** — pages `gh api orgs/<org>/repos?type=all` for each of
   `utopia-php`, `appwrite`, `appwrite-labs`, `open-runtimes`. Hydrates
   into typed `Repository` DTOs.
2. **Diff** — for utopia-php, expects a 1:1 mapping `repo` ↔
   `utopia-<repo>-expert`. Reports:
   - **missing** — repo with no skill → CI prompt scaffolds a new SKILL.md
   - **orphaned** — skill with no repo (renamed/private)
   - **archived** — repo archived but skill remains
3. **Filter** — for appwrite, only flags `sdk-for-*` / `integration-for-*` /
   `mcp-for-*` not in the tracked allowlist (signal vs. noise).
4. **Inventory** — for `appwrite-labs` and `open-runtimes`, emits a
   bare repo list for context (no diff, no judgement).

Auth comes from `gh auth login`, so private repos visible to your
account are included.

### `validate` — marketplace-wide checks

`src/Validation/Validator.php` walks the plugin tree once and verifies:

| Check | Files |
|---|---|
| YAML frontmatter parses, `name` matches directory, `description` ≤ 500 chars, no duplicate names | `plugins/*/skills/*/SKILL.md` |
| Frontmatter has `description` ≤ 500 chars, no duplicate filenames | `plugins/*/commands/*.md` |
| Frontmatter has `name` and `description`, no duplicate names | `plugins/*/agents/*.md` |
| Valid JSON object with `name` and `description` | `.claude-plugin/marketplace.json`, `plugins/*/.claude-plugin/plugin.json` |
| Valid JSON | `plugins/*/hooks/hooks.json` |
| Object with `skill_name` + `evals[]`, every eval has `prompt` + `expected_output` | `plugins/*/evals/evals.json` |
| Array of `{query, should_trigger}` entries | `plugins/*/evals/trigger-*.json` |

Exit 0 + `OK: <counts>` summary on success; exit 1 + per-error stderr
on failure.

### `index` — regenerate the utopia INDEX

`src/Index/Generator.php`:

1. Globs every `plugins/utopia-experts/skills/*/SKILL.md`.
2. Parses each frontmatter via `Marketplace\Markdown\Frontmatter`.
3. Resolves a category for each skill via `Catalogue::lookup($name)`
   (10 categories + `Other` fallback, defined in
   `src/Index/Catalogue.php`).
4. Emits a Markdown table per category plus the seven canonical
   pairings as a "Composition notes for the router" footer.
5. Writes `plugins/utopia-experts/skills/INDEX.md`.

Idempotent — a clean run produces a byte-identical file. CI verifies
this with `git diff --exit-code` against the committed copy.

---

## Self-improving automation

Three GitHub Actions workflows. Together they keep the marketplace
current with upstream and let the maintainer drive changes from PR
review comments instead of file edits.

```
                           Mon 04:30 UTC
                                │
                                ▼
            ┌───────────────────────────────┐
            │     sync-libraries.yml        │
            │  bin/marketplace sync         │
            │  → if drift, Claude scaffolds │
            │    missing utopia skills,     │
            │    regenerates INDEX.md       │
            │  → opens PR on bot/sync-libs  │
            └────────────┬──────────────────┘
                         │
         (an hour later, with new skills already in main)
                         │
                Mon 06:00 UTC
                         ▼
            ┌───────────────────────────────┐
            │      scan-commits.yml         │
            │  Pulls 7d of commits from     │
            │  every repo in scan-repos.txt │
            │  → Claude groups by theme,    │
            │    proposes new and modified  │
            │    commands/skills/agents/    │
            │    hooks (no cap)             │
            │  → opens PR on bot/scan-cmt   │
            └────────────┬──────────────────┘
                         │
                  PR opens on bot/*
                         ▼
            ┌───────────────────────────────┐
            │         claude.yml            │
            │  • bot/* PR → Claude self-    │
            │    review: re-runs validators │
            │    + fact-checks each scaffold│
            │    against cited upstream     │
            │  • @claude mention in any PR  │
            │    comment / review / issue → │
            │    Claude applies the change, │
            │    runs tests, pushes commit  │
            └───────────────────────────────┘
```

| Workflow | Trigger | Mechanism |
|---|---|---|
| `sync-libraries.yml` | Mon 04:30 UTC + manual | Runs `bin/marketplace sync --fail-on-drift=true`; on non-zero, dispatches `anthropics/claude-code-action@v1` with a prompt that reads `drift.json`, scaffolds new `utopia-*-expert/SKILL.md` files (grounded via `gh` against the upstream README), updates `Catalogue::all()`, regenerates `INDEX.md`, runs validators + PHPUnit, then `peter-evans/create-pull-request@v8` opens a PR on `bot/sync-libraries`. |
| `scan-commits.yml` | Mon 06:00 UTC + manual | Reads `.github/scan-repos.txt` (or a `workflow_dispatch` override), pulls commits via `gh api repos/<repo>/commits?since=…` for every listed repo, dumps to `commits.md`, then Claude Code groups themes and is allowed to ADD new skills / commands / agents / hooks **and** MODIFY existing ones when an upstream library has actually changed. Writes `SUGGESTIONS.md` with citations. PR on `bot/scan-commits`. |
| `claude.yml` | `pull_request` on `bot/*`, `issue_comment` / `pull_request_review` / `pull_request_review_comment` / `issues` containing `@claude` | Two modes: (1) auto-review of every bot/* PR (re-run validators, fact-check scaffolds against cited commits, post a single review comment); (2) `@claude …` mentions trigger Claude to read context, apply the requested change, run validators, and push to the PR branch. |

Auth: either `ANTHROPIC_API_KEY` **or** `CLAUDE_CODE_OAUTH_TOKEN` in
repo secrets. Org reads + PR creation use the default `GITHUB_TOKEN`.

To prune what gets scanned, edit `.github/scan-repos.txt` — one
`org/repo` per line, `#` comments allowed. Or pass `repos_override` via
`workflow_dispatch`.

---

## CI

`.github/workflows/test.yml`, three parallel jobs on every push and PR
to `main`:

| Job | Verifies |
|---|---|
| `phpunit` | All 122 tests pass: 27 Sync, 17 Validation, 12 Index, 66 Hook (subprocess-based, one class per hook). |
| `validate` | `bin/marketplace validate` exits 0 against the full plugin tree. |
| `index` | `bin/marketplace index` produces a byte-identical `INDEX.md` to the committed copy. |

All actions pinned to commit SHAs (`actions/checkout@de0fac2…`,
`shivammathur/setup-php@accd6127…`,
`anthropics/claude-code-action@b3c0320…`,
`peter-evans/create-pull-request@5f6978fa…`).

---

## Repository layout

```
appwrite-claude-marketplace/
├── .claude-plugin/marketplace.json
├── .github/
│   ├── scan-repos.txt                # weekly scan whitelist
│   └── workflows/
│       ├── test.yml                  # 3-job CI
│       ├── sync-libraries.yml        # weekly utopia drift PR
│       ├── scan-commits.yml          # weekly commit-driven PR
│       └── claude.yml                # @claude + bot/* self-review
├── composer.json                     # PHP 8.3+, utopia-php/{cli,console,validators}, PHPUnit 12
├── phpunit.xml.dist
├── bin/marketplace                   # Utopia\CLI: sync | validate | index
├── src/
│   ├── Sync/                         # Detector, GhClient, *Report DTOs
│   ├── Markdown/Frontmatter.php
│   ├── Validation/Validator.php
│   └── Index/{Generator,Catalogue,Category,Report}.php
├── tests/                            # PHPUnit, mirrors src/ namespace
│   ├── Sync/        Validation/      Index/        Hook/
└── plugins/
    ├── appwrite-hooks/hooks/         # 7 *.php guards + _shared.php + hooks.json
    ├── appwrite-skills/commands/     # 5 slash commands + CLAUDE.md/AGENTS.md
    ├── appwrite-conventions/         # CLAUDE.md priors + 2 reference SKILL.md
    └── utopia-experts/
        ├── skills/                   # 50 expert SKILL.md + INDEX.md
        └── agents/utopia-router.md
```

---

## Install

### Claude Code

```
/install-plugin abnegate/appwrite-claude-marketplace
```

Or pick individual plugins:

```
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-hooks
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-skills
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-conventions
/install-plugin abnegate/appwrite-claude-marketplace --plugin utopia-experts
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-experts
```

### OpenAI Codex CLI

`AGENTS.md` is a symlink to `CLAUDE.md`, so Codex reads the same files
natively:

```bash
cp plugins/appwrite-conventions/AGENTS.md your-project/AGENTS.md
cp -r plugins/utopia-experts/skills    your-project/.codex/skills
```

Hooks are Claude Code-specific (Codex has no equivalent surface), but
all other plugin types port directly.

---

## Develop

```bash
composer install
vendor/bin/phpunit                   # full 122-test suite
bin/marketplace validate             # frontmatter + manifests
bin/marketplace index                # regenerate INDEX.md (idempotent)
bin/marketplace sync                 # show upstream drift
```

### Adding a hook

1. Create `plugins/appwrite-hooks/hooks/your_guard.php` — `require_once __DIR__ . '/_shared.php'`, then `use function Marketplace\Hook\Shared\{read_tool_input, allow, block, skip}`. Procedural style: read the payload, decide, call `allow|block|skip`. Don't load Composer autoload — hooks must run in a fresh install.
2. Register it in `plugins/appwrite-hooks/hooks/hooks.json` under the appropriate `PreToolUse` matcher with `"command": "php ${CLAUDE_PLUGIN_ROOT}/hooks/your_guard.php"`.
3. Add `tests/Hook/YourGuardTest.php` extending `HookTestCase` — `self::callHook(...)` subprocess-invokes the script with a JSON payload and returns `[exitCode, stderr]`.
4. `vendor/bin/phpunit --testsuite Hook`.

### Adding a slash command

1. `plugins/<plugin>/commands/<name>.md` with frontmatter `description: …` (≤ 500 chars). Body is the prompt the agent runs.
2. `bin/marketplace validate`.

### Adding a skill

1. `plugins/<plugin>/skills/<name>/SKILL.md` with frontmatter `name: <name>` (matching the directory) and `description: …` (≤ 500 chars). The description is the only field the agent reads when deciding whether to load the body — make it specific.
2. `bin/marketplace validate`.
3. For `utopia-experts`: also add the skill to a category in `src/Index/Catalogue.php` and run `bin/marketplace index`.

### Adding an agent

1. `plugins/<plugin>/agents/<name>.md` with frontmatter `name` + `description`. Body is the agent's system prompt.
2. `bin/marketplace validate`.

---

## Multi-tool support

`CLAUDE.md` is the single source of truth for project instructions.
`AGENTS.md` is a symlink, so OpenAI Codex CLI reads the exact same
file. No generation, no sync — one file, two names.

## License

MIT
