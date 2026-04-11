# appwrite-hooks

PreToolUse hooks that enforce commit/push/edit-time discipline so you
can't accidentally ship against your own rules.

Seven hooks across three matchers. Each is independent — they all read
the same stdin payload and exit 0 to allow or 2 (with a stderr message)
to block. Hooks that don't apply to the current call exit silently. All
decisions are logged to `~/.claude/metrics/appwrite-hooks.jsonl` for
later analysis.

## Matchers

```
PreToolUse.Bash                 → destructive_guard
                                → force_push_guard
                                → no_verify_guard
                                → conventional_commit
                                → regression_test
                                → format_lint
PreToolUse.Edit|Write|MultiEdit → secrets_guard
```

Hooks run in the order listed above: cheapest/fastest-fail first, heaviest
(format/lint) last.

## Hooks

### `destructive_guard_hook.py`

Blocks classic destructive shell commands. Catches:
- `rm -rf /`, `rm -rf ~`, `rm -rf $VAR`, `rm -rf /*`
- `rm -rf` with no explicit target (empty variable expansion)
- `rm -rf` on system paths (`/home`, `/etc`, `/var`, etc.)
- `dd of=/dev/...` (disk overwrite)
- `mkfs.*` (filesystem creation)
- `find / -delete`
- `chmod -R 000|777` on system paths

Allows `rm -rf` in safe scratch locations: `node_modules`, `dist`,
`build`, `.next`, `target`, `.cache`, `coverage`, `__pycache__`,
`vendor`, `/tmp/*`, and files ending in `.log`/`.tmp`/`.lock`/`.pid`.

Override: `APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1`.

### `force_push_guard_hook.py`

Blocks `git push --force` / `-f` / `--force-with-lease` when the target
is a protected branch: `main`, `master`, `trunk`, `develop`, any
`\d+\.\d+\.x` version branch (e.g. `1.9.x`), or `release/*`, `hotfix/*`.
Also blocks force-push with no explicit target (can't verify the
upstream).

Force-pushing your own feature branch is allowed — that's normal
iteration workflow.

Override: `APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1`.

### `no_verify_guard_hook.py`

Blocks `git commit --no-verify` and `git commit --amend`.

Rationale: `--no-verify` defeats the rest of these hooks. `--amend`
rewrites the wrong commit when a hook has failed — you want a new
commit, not a modified previous one.

Override: `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1`.

### `conventional_commit_hook.py`

Requires the first line of the commit message to match
`(type): subject`, where type is one of `feat`, `fix`, `refactor`,
`chore`, `docs`, `test`, `style`, `perf`, `revert`, `ci`, `cleanup`,
`improvement`, or `build`. Merge commits (`Merge …`, `Revert …`) are
exempt.

No override — if you want a non-conventional message, use `Merge` or
`Revert` as the first word, or fix the message.

### `regression_test_hook.py`

When the commit message starts with `(fix):`, confirms at least one
staged file matches a test path pattern:

```
tests/ or test/ anywhere in the path
__tests__/ or spec/ directories
Test.{php,kt,java,cs,scala,groovy}
_test.{go,py,rb,ts,tsx,js,jsx}
.spec.{ts,tsx,js,jsx}
_spec.{rb,py}
```

Enforces the global rule: "Every bug fix must include a regression test
that fails without the fix and passes with it." A missing test blocks
the commit with the list of staged files so you can see what's there.

Override: `APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1` (for doc typos
mislabeled as `(fix):`, infra-only fixes with nothing to test).

### `format_lint_hook.py`

Detects the ecosystem from the staged file set and runs the matching
format + lint tool before allowing the commit:

| Staged files | Tool |
|---|---|
| `*.php` + `composer.json` | `composer lint` |
| `*.kt` / `*.kts` + `gradlew` | `./gradlew ktlintCheck` |
| `*.kt` / `*.kts` without gradlew | `ktlint` |
| `*.rs` + `Cargo.toml` | `cargo fmt --check && cargo clippy --all-targets -- -D warnings` |
| `*.ts` / `*.tsx` / `*.js` / `*.jsx` / `*.mjs` / `*.cjs` + `package.json` | `npx --no-install prettier --check .` |
| `*.py` | `ruff check .` (if `ruff` on PATH) |

Missing tools are logged and skipped — the hook catches regressions
when the toolchain is set up, not blocks unrelated projects.

Override: `APPWRITE_HOOKS_SKIP_LINT=1`.

### `secrets_guard_hook.py`

Blocks Edit/Write/MultiEdit targeting secret-bearing files:
- `.env` at any level (but NOT `.env.example`/`.env.sample`/`.env.template`)
- `credentials*`, `secrets*`
- `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`, `*.asc`
- `id_rsa`, `id_ed25519`, `id_ecdsa`, `id_dsa`
- `kubeconfig*`, `.netrc`, `.pgpass`, `.aws/credentials`
- `*.token`

Also scans write content for obvious secret patterns:
- AWS access key IDs (`AKIA…`)
- AWS secret access keys
- OpenAI-style keys (`sk-…`)
- GitHub PATs (`ghp_…`, `github_pat_…`)
- Slack bot tokens (`xoxb-…`)
- Private key blocks (`-----BEGIN … PRIVATE KEY-----`)
- Postgres/MongoDB connection strings with credentials

Allowlisted paths: `*.example`, `*.sample`, `*.template`, `*.dist`,
`*.tpl`, and anything under `test*/`, `tests/`, `fixtures/`.

Override: `APPWRITE_HOOKS_ALLOW_SECRETS=1`.

## Environment variables

### Per-call overrides

Each escape-hatch variable is opt-in and scoped to one command. They
exist because the rule "never override" is wrong in about 1% of cases
and you need a clean way to handle them without disabling the hooks
globally.

```bash
APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1    rm -rf ~/Downloads/old-stuff
APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1    git push --force origin main
APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1  git commit --amend -m "(fix): y"
APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1 git commit -m "(fix): doc typo"
APPWRITE_HOOKS_ALLOW_SECRETS=1        # edit a file that looks secret
APPWRITE_HOOKS_SKIP_LINT=1            git commit -m "(chore): version bump"
```

### Global toggles

```bash
# Dry-run: hooks log what they WOULD do but exit 0 always.
# Useful for probing "what would these catch on my current branch?"
# without changing behaviour. Logs each would-block decision to the
# metrics file for later inspection.
APPWRITE_HOOKS_DRY_RUN=1

# Disable the metrics JSONL entirely. Default is to log every decision
# to ~/.claude/metrics/appwrite-hooks.jsonl.
APPWRITE_HOOKS_NO_METRICS=1
```

## Metrics

Every hook decision (allowed/blocked/would-block/skipped) gets a
one-line JSONL entry at `~/.claude/metrics/appwrite-hooks.jsonl`. Log
failures are silent — a disk/permission error never breaks the hook
pipeline.

Record shape:
```json
{"ts":"2026-04-12T01:34:22+00:00","hook":"force_push_guard","tool":"Bash","verdict":"blocked","reason":"force-push to main"}
```

Verdicts:
- `allowed` — hook was applicable and decided to allow
- `blocked` — hook was applicable and decided to block
- `would-block` — hook would have blocked but `DRY_RUN` was set
- `skipped` — hook didn't apply to this call at all

## Tests

```bash
APPWRITE_HOOKS_NO_METRICS=1 python3 hooks/test_hooks.py
```

50 tests across 6 test classes covering the happy paths and edge cases.
Tests set `APPWRITE_HOOKS_NO_METRICS=1` so they don't pollute the real
metrics file. Run before modifying any shared parser behavior.
