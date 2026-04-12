# appwrite-claude-marketplace

AI coding-agent plugins tuned for the Appwrite / Utopia / Swoole stack.
Works with **Claude Code** and **OpenAI Codex CLI** (`AGENTS.md` symlinks
to `CLAUDE.md` — same file, zero duplication).

Four independent plugins, each addressing a specific source of friction:

| Plugin | Purpose |
|---|---|
| **appwrite-hooks** | 7 PreToolUse guards enforcing commit discipline, push safety, secret protection, and destructive-command blocking |
| **appwrite-skills** | 6 slash commands for parallel research fanout, Swoole auditing, merge-conflict resolution, and marketplace discovery |
| **appwrite-conventions** | Auto-loaded framework priors for backend (PHP/Utopia/Swoole), frontend (SvelteKit/Svelte 5), and infrastructure (Terraform/DO/Cloudflare/K8s) |
| **utopia-experts** | 50 per-library expert skills + a routing agent for the entire utopia-php ecosystem |

**86 tests across 3 suites. 14 evals + 48 trigger queries. 4-job CI.**

## Install

### Claude Code

```
/install-plugin abnegate/appwrite-claude-marketplace
```

Or install individual plugins:

```
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-hooks
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-skills
/install-plugin abnegate/appwrite-claude-marketplace --plugin appwrite-conventions
/install-plugin abnegate/appwrite-claude-marketplace --plugin utopia-experts
```

### OpenAI Codex CLI

Copy the plugin directories into your project. Codex reads `AGENTS.md`
(symlinked to `CLAUDE.md`) and `SKILL.md` files natively:

```bash
cp plugins/appwrite-conventions/AGENTS.md your-project/AGENTS.md
cp -r plugins/utopia-experts/skills your-project/.codex/skills
```

Plugins are independent — install any combination.

---

## appwrite-hooks

Seven `PreToolUse` hooks across two matchers. Every hook reads JSON from
stdin (tool name + input), exits 0 to allow, exits 2 to block with a
stderr message surfaced to the agent. All hooks support dry-run mode
(`APPWRITE_HOOKS_DRY_RUN=1`) and emit metrics to
`~/.claude/metrics/appwrite-hooks.jsonl`.

### Bash matcher (6 hooks)

| Hook | Blocks | Override |
|---|---|---|
| `destructive_guard` | `rm -rf` on system/home paths, `~`, variable expansions. Handles `sudo`/`env` prefixes and pipe-chained commands. | `APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1` |
| `force_push_guard` | `--force` / `--force-with-lease` / `+refspec` to main, master, trunk, develop, `N.N.x`, release/\*, hotfix/\*. Handles `=`-delimited flags and combined short flags (`-uf`). | `APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1` |
| `staged_diff_scan` | Conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`) in staged diffs — no override. Also blocks TEMP/HACK/FIXME/XXX markers and `console.log` in JS/TS/Svelte files. | `APPWRITE_HOOKS_ALLOW_TEMP_CODE=1` (temp markers only) |
| `conventional_commit` | Commit messages not matching `(type): subject`. Also blocks `--no-verify` and `--amend` flags. | `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1` (flags only) |
| `regression_test` | `(fix):` commits with no staged test file | `APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1` |
| `format_lint` | Commits whose staged files fail the ecosystem linter (composer lint / ktlintCheck / cargo fmt+clippy / prettier / ruff) | `APPWRITE_HOOKS_SKIP_LINT=1` |

### Edit|Write|MultiEdit matcher (1 hook)

| Hook | Blocks | Override |
|---|---|---|
| `secrets_guard` | Writes to `.env`, `credentials`, `*.pem`, `*.key`, `id_rsa`, `kubeconfig`, `*.token`, `.netrc`, `.pgpass`. Also scans content for AWS keys, OpenAI keys, GitHub PATs, Slack tokens, private key blocks, and database connection strings with credentials. | `APPWRITE_HOOKS_ALLOW_SECRETS=1` |

### Shared infrastructure

`_shared.py` provides:
- `read_tool_input()` — stdin JSON parsing (fail-open on bad input)
- `extract_git_commit()` / `extract_git_push()` — command extraction with `KEY=VALUE`/`sudo`/`env` prefix stripping
- `staged_files()` / `staged_diff()` — git subprocess helpers
- `has_flag()` — flag detection with `--flag=value` and combined short-flag support
- `block()` / `allow()` / `skip()` — exit protocol with metric logging and dry-run support

---

## appwrite-skills

Six slash commands and a `CLAUDE.md` fragment with three workflow rules.

### Commands

| Command | Purpose |
|---|---|
| `/fanout <task>` | Decompose a task into 3-5 parallel research subagents before editing. Keeps expensive Opus parent context clean by shifting reads/greps onto Haiku subagents. |
| `/swoole-audit [path]` | Scan a PHP+Swoole project for 11 categories of pitfalls: runtime hooks, blocking I/O, shared state, pools, long-running footguns, concurrency, server config, signals, shared memory, extension compat, testing. Outputs P0/P1/P2 findings. |
| `/swoole-fix [findings]` | Fix findings from `/swoole-audit` using the `swoole-expert` skill as primary reference. Classifies fixes as mechanical/structural/uncertain, dispatches parallel fix agents, returns diffs for review. |
| `/merge-conflict [branch]` | Resolve conflicts by analyzing the intent of each side. Classifies files as orthogonal/overlapping/contradictory, auto-resolves the first two, leaves contradictory files for human review. |
| `/marketplace-help [filter]` | List every command, skill, agent, and hook across all plugins with one-line descriptions from frontmatter. Optional substring filter. |

### Workflow rules (CLAUDE.md)

1. **Opus for edits, Haiku for research** — parent sessions that edit code run on Opus. Read-only research runs on Haiku subagents.
2. **Multi-repo tasks start in plan mode** — any task touching >1 repo enters plan mode before the first edit.
3. **Edit over Write, always** — prefer surgical `Edit` over full-file `Write`. `Write` is for new files only.

---

## appwrite-conventions

Auto-loaded context encoding stable framework priors so every session
starts with the right assumptions. Three domain sections:

- **Backend (PHP/Utopia/Swoole)** — framework choice (Utopia, not Laravel), Swoole 6 runtime, namespace layout, composer constraints, code style, REST conventions, common bug patterns from 600+ fix-commit analysis
- **Frontend (SvelteKit/Svelte 5)** — Console stack, bun tooling, Pink design system, route structure, SDK usage, runes migration, wizard/notification/analytics patterns
- **Infrastructure (Terraform/DO/Cloudflare/K8s)** — provider pinning, state management, naming/tagging, secrets, module structure, safety rules

Plus two on-demand reference skills:

| Skill | Purpose |
|---|---|
| `utopia-patterns` | Cross-cutting cheat sheet for Utopia PHP ecosystem idioms |
| `swoole-expert` | Deep reference for production Swoole PHP (5.x/6.x) — the primary citation source for `/swoole-audit` and `/swoole-fix` |

---

## utopia-experts

50 expert-level skills — one per library in the `utopia-php` ecosystem — plus
a routing agent that reads the skill index and dispatches 1-3 relevant experts.

### Categories

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

Each skill captures the library's public API surface, core patterns,
gotchas, and leverage opportunities sourced from deep research across
the `utopia-php` GitHub org.

### Router agent

The `utopia-router` agent reads `skills/INDEX.md` (auto-generated by
`scripts/generate_index.py`), picks the 1-3 most relevant skills for a
question, loads them, and returns a synthesized answer. Known multi-skill
pairings (observability pipeline, Swoole pool stack, SDK regen cascade,
custom-domain onboarding, rate limiting, messaging worker) are documented
in the index so the router loads all relevant skills together.

Use `/utopia? <question>` to invoke explicitly, or let Claude Code's
skill-trigger system route automatically.

---

## Repository layout

```
appwrite-claude-marketplace/
├── .claude-plugin/
│   └── marketplace.json              # marketplace manifest (v0.1.0)
├── .github/workflows/
│   └── test.yml                      # 4-job CI pipeline
├── scripts/
│   └── validate_skills.py            # frontmatter + manifest + eval validation
├── tests/
│   ├── test_validate_skills.py       # 17 tests
│   └── test_generate_index.py        # 7 tests
├── plugins/
│   ├── appwrite-hooks/
│   │   ├── .claude-plugin/plugin.json
│   │   ├── hooks/
│   │   │   ├── hooks.json            # hook registration (7 hooks, 2 matchers)
│   │   │   ├── _shared.py            # shared helpers (278 lines)
│   │   │   ├── destructive_guard_hook.py
│   │   │   ├── force_push_guard_hook.py
│   │   │   ├── staged_diff_scan_hook.py
│   │   │   ├── conventional_commit_hook.py
│   │   │   ├── regression_test_hook.py
│   │   │   ├── format_lint_hook.py
│   │   │   └── test_hooks.py         # 62 tests
│   │   └── README.md
│   ├── appwrite-skills/
│   │   ├── .claude-plugin/plugin.json
│   │   ├── commands/
│   │   │   ├── fanout.md
│   │   │   ├── swoole-audit.md
│   │   │   ├── swoole-fix.md
│   │   │   ├── merge-conflict.md
│   │   │   └── marketplace-help.md
│   │   ├── evals/                    # 6 evals + 32 trigger queries
│   │   ├── CLAUDE.md
│   │   └── AGENTS.md -> CLAUDE.md
│   ├── appwrite-conventions/
│   │   ├── .claude-plugin/plugin.json
│   │   ├── skills/
│   │   │   ├── utopia-patterns/SKILL.md
│   │   │   └── swoole-expert/SKILL.md
│   │   ├── CLAUDE.md
│   │   └── AGENTS.md -> CLAUDE.md
│   └── utopia-experts/
│       ├── .claude-plugin/plugin.json
│       ├── skills/                   # 50 expert SKILL.md files
│       │   ├── INDEX.md              # auto-generated catalogue
│       │   └── utopia-*-expert/SKILL.md
│       ├── agents/
│       │   └── utopia-router.md
│       ├── evals/                    # 8 evals + 16 trigger queries
│       └── scripts/
│           └── generate_index.py
├── COMPOSITION.md
├── LICENSE                           # MIT
└── README.md
```

---

## Development

### Run tests

```bash
# Hook tests (62 tests)
APPWRITE_HOOKS_NO_METRICS=1 python3 plugins/appwrite-hooks/hooks/test_hooks.py

# Validator + generator tests (24 tests)
python3 tests/test_validate_skills.py
python3 tests/test_generate_index.py

# Frontmatter + manifest + eval validation
python3 scripts/validate_skills.py
```

### Regenerate the utopia-experts index

```bash
python3 plugins/utopia-experts/scripts/generate_index.py
```

Run this after adding, removing, or renaming any skill. CI verifies the
index is up to date.

### CI

The GitHub Actions workflow (`.github/workflows/test.yml`) runs four
parallel jobs on every push and PR to `main`:

| Job | What it checks |
|---|---|
| `hooks` | All 62 hook tests pass |
| `unit-tests` | Validator and generator unit tests pass |
| `validate` | Every SKILL.md, command .md, agent .md, plugin.json, and eval .json has valid structure |
| `index` | `INDEX.md` matches the output of `generate_index.py` (no stale index) |

### Adding a hook

1. Create `plugins/appwrite-hooks/hooks/your_hook.py` — import from `_shared`
2. Register it in `hooks.json` under the appropriate matcher
3. Add tests to `test_hooks.py`
4. Run the test suite

### Adding a skill

1. Create `plugins/<plugin>/skills/<name>/SKILL.md` with frontmatter (`name`, `description`)
2. The `name` field must match the directory name
3. Run `python3 scripts/validate_skills.py` to verify
4. For utopia-experts: also run `generate_index.py` and add the skill to the `CATEGORIES` dict

### Adding a command

1. Create `plugins/<plugin>/commands/<name>.md` with frontmatter (`description`)
2. Run `python3 scripts/validate_skills.py` to verify

---

## Multi-tool support

`CLAUDE.md` is the single source of truth for project instructions.
`AGENTS.md` is a symlink to it, so OpenAI Codex CLI reads the exact
same file. No generation step, no sync — one file, two names.

## License

MIT
