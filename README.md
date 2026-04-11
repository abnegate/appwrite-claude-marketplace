# appwrite-claude-marketplace

Claude Code plugin marketplace tuned for the Appwrite / Utopia / Swoole
development workflow. Three plugins, each addressing a specific source
of friction in day-to-day work on the Appwrite stack.

## Install

```
/plugin marketplace add abnegate/appwrite-claude-marketplace
/plugin install appwrite-hooks@appwrite-claude-marketplace
/plugin install appwrite-skills@appwrite-claude-marketplace
/plugin install appwrite-conventions@appwrite-claude-marketplace
```

Pick the plugins you want — they're independent and can be installed
separately.

## What's inside

### `appwrite-hooks` — commit-time discipline

Four `PreToolUse` hooks that enforce the rules you already wrote for
yourself. Because a rule you don't automate is a rule you violate.

| Hook | Blocks |
|---|---|
| `no_verify_guard_hook` | `git commit --no-verify` and `git commit --amend` |
| `conventional_commit_hook` | Commit messages not matching `(type): subject` |
| `regression_test_hook` | `(fix):` commits with no staged test file |
| `format_lint_hook` | Commits whose staged files fail `composer lint` / `ktlintCheck` / `cargo fmt+clippy` / `prettier --check` / `ruff check` |

All four are opt-out per-command via environment variables
(`APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT`, `APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST`,
`APPWRITE_HOOKS_SKIP_LINT`). See `plugins/appwrite-hooks/README.md` for
details.

17 unit tests in `plugins/appwrite-hooks/hooks/test_hooks.py`.

### `appwrite-skills` — workflow commands + rules

Three slash commands and a CLAUDE.md fragment with three workflow rules.

**Commands:**
- `/fanout <task>` — decompose a task into 3-5 parallel research
  subagents before editing. For expensive-per-session repos, this cuts
  Opus parent context dramatically by shifting Reads / Greps onto
  Haiku subagents.
- `/swoole-audit [path]` — scan a PHP + Swoole project for pool
  exhaustion, coroutine-hook ordering, shared socket bugs, and missing
  per-worker pool init. Outputs P0/P1/P2 findings with file:line refs.
- `/merge-conflict [base-branch]` — resolve conflicts by intent:
  classifies each file as orthogonal / overlapping / contradictory,
  auto-resolves the first two, leaves contradictory files for human
  review.

**Rules (CLAUDE.md):**
1. Opus for parent sessions that edit; Haiku for research subagents.
2. Multi-repo tasks start in plan mode.
3. `Edit` over `Write`, always.

### `appwrite-conventions` — framework context compression

Auto-loaded CLAUDE.md encoding the stable framework priors (Utopia,
Swoole 6, namespace layout, composer constraints, code style, REST
conventions, test discipline, branch strategy). Plus a
`utopia-patterns` reference skill for the common routing / DI /
database / pool / validator idioms.

The point: every Appwrite-stack session starts with the right priors
instead of re-deriving "is this Laravel?" and "what's the namespace
convention?" from scratch.

## Repository layout

```
appwrite-claude-marketplace/
├── .claude-plugin/
│   └── marketplace.json         # marketplace manifest
├── plugins/
│   ├── appwrite-hooks/
│   │   ├── .claude-plugin/plugin.json
│   │   ├── hooks/
│   │   │   ├── hooks.json
│   │   │   ├── _shared.py        # git-commit parser helpers
│   │   │   ├── conventional_commit_hook.py
│   │   │   ├── regression_test_hook.py
│   │   │   ├── format_lint_hook.py
│   │   │   ├── no_verify_guard_hook.py
│   │   │   └── test_hooks.py     # 17 unit tests
│   │   └── README.md
│   ├── appwrite-skills/
│   │   ├── .claude-plugin/plugin.json
│   │   ├── commands/
│   │   │   ├── fanout.md
│   │   │   ├── swoole-audit.md
│   │   │   └── merge-conflict.md
│   │   ├── CLAUDE.md             # workflow rules
│   │   └── README.md
│   └── appwrite-conventions/
│       ├── .claude-plugin/plugin.json
│       ├── CLAUDE.md             # framework priors
│       ├── skills/
│       │   └── utopia-patterns/
│       │       └── SKILL.md
│       └── README.md
└── README.md
```

## Development

Hooks:
```bash
cd plugins/appwrite-hooks/hooks && python3 test_hooks.py
```

Skills / commands / CLAUDE.md fragments are plain Markdown — no build
step.

## License

MIT
