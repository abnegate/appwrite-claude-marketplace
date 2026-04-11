# appwrite-hooks

PreToolUse hooks that enforce commit-time discipline so you can't
accidentally ship against your own rules.

Four hooks run in order on every `git commit` invocation Claude issues.
Each is independent — they all read the same stdin payload and exit 0 to
allow or 2 (with a stderr message) to block. Hooks that don't apply to
the current command exit silently.

## Hooks

### `no_verify_guard_hook.py`

Blocks `git commit --no-verify` and `git commit --amend` unless the user
has explicitly authorized bypassing the gate. If you genuinely need to
skip, set `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1` in the environment for
the one command.

Rationale: the global rules forbid both flags without explicit opt-in.
`--no-verify` defeats the rest of these hooks. `--amend` rewrites the
wrong commit when a hook has failed — you want a new commit, not a
modified previous one.

### `conventional_commit_hook.py`

Requires the first line of the commit message to match
`(type): subject`, where type is one of `feat`, `fix`, `refactor`,
`chore`, `docs`, `test`, `style`, `perf`, `revert`, `ci`, `cleanup`,
`improvement`, or `build`. Merge commits (`Merge …`, `Revert …`) are
exempt.

Rejects on: missing type, unknown type, bare `feat: x` (no parens),
empty message.

### `regression_test_hook.py`

When the commit message starts with `(fix):`, confirms at least one
staged file matches a test path pattern:

```
tests/ or test/ anywhere in the path
__tests__/ or spec/ directories
Test.php / Test.kt / Test.java / Test.cs / Test.scala / Test.groovy
_test.go / _test.py / _test.rb / _test.ts / _test.tsx / _test.js / _test.jsx
.spec.ts / .spec.tsx / .spec.js / .spec.jsx
_spec.rb / _spec.py
```

Enforces the global rule: "Every bug fix must include a regression test
that fails without the fix and passes with it." A missing test blocks
the commit with the list of staged files so you can see exactly what's
there.

Override for edge cases (doc typo fix mislabeled as `(fix):`,
infrastructure-only fix with nothing to test):
`APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1`.

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

Missing tools are logged and skipped — the hook's job is to catch
regressions when the toolchain is set up, not to block unrelated
projects. Commits in a repo without the expected tooling pass through.

Override: `APPWRITE_HOOKS_SKIP_LINT=1`.

## Hook ordering

`hooks.json` runs the four scripts in this order:

1. `no_verify_guard_hook` — cheapest, fastest fail
2. `conventional_commit_hook` — message shape check
3. `regression_test_hook` — staged file scan
4. `format_lint_hook` — actual lint run (slowest; only reached if everything else passed)

Each hook only runs the work that matters to it and exits 0 for anything
it doesn't care about, so the pipeline stays cheap for non-git-commit
Bash commands.

## Tests

```bash
python3 hooks/test_hooks.py
```

17 tests cover the happy paths and the parser edge cases (chained
commands, HEREDOC messages, non-Bash tools, non-git Bash). Run these
before modifying any shared parser behavior.

## Escape hatches

All three escape-hatch environment variables are opt-in and scoped to
one command. None of them persist. They exist because the rule "never
override" is wrong in about 1% of cases and you need a clean way to
handle them without disabling the hooks globally.

```bash
APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1 git commit --amend -m "(fix): y"
APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1 git commit -m "(fix): doc typo"
APPWRITE_HOOKS_SKIP_LINT=1 git commit -m "(chore): bump version only"
```
