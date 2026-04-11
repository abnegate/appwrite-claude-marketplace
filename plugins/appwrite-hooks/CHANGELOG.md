# Changelog — appwrite-hooks

All notable changes to this plugin.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] — 2026-04-12

### Added
- `destructive_guard_hook.py` — blocks `rm -rf` on system paths, glob
  at root, variable expansion, missing targets, unrecognised absolute
  paths. Also catches `dd of=/dev/*`, `mkfs.*`, `find / -delete`, and
  `chmod -R 000|777` on absolute paths. Allows standard scratch
  targets (`node_modules`, `dist`, `build`, `.next`, `target`,
  `.cache`, `/tmp/*`, `*.log`, `*.tmp`).
- `force_push_guard_hook.py` — blocks `git push --force` /
  `--force-with-lease` / `+refspec` when targeting `main`, `master`,
  `trunk`, `develop`, version branches (`\d+\.\d+\.x`), or
  `release/*`/`hotfix/*`. Also blocks force-push with no explicit
  target. Allows force-push to feature branches.
- `secrets_guard_hook.py` — PreToolUse hook on Edit/Write/MultiEdit
  that blocks writes to `.env`, `credentials*`, `*.pem`, `*.key`,
  `id_rsa`, `.netrc`, `.pgpass`, `.aws/credentials`, `*.token`, etc.
  Also scans write content for AWS/OpenAI/GitHub/Slack token
  patterns and private key blocks. Allowlists `.env.example`,
  `.env.sample`, `.env.template`, and `test/`/`fixtures/` paths.
- Metrics JSONL logging to `~/.claude/metrics/appwrite-hooks.jsonl`.
  Every hook decision (allowed/blocked/would-block/skipped) gets a
  one-line record with tool, hook, verdict, and reason. Opt out via
  `APPWRITE_HOOKS_NO_METRICS=1`.
- Dry-run mode via `APPWRITE_HOOKS_DRY_RUN=1`. Under dry-run, blocks
  become would-blocks: the hook prints the rejection message with a
  `[DRY RUN — would block]` prefix but exits 0. Used for probing
  "what would these hooks catch?" without changing behaviour.
- `extract_git_push()` and refactored `_extract_git_subcommand()` in
  `_shared.py` — both push and commit parsing now flow through a
  single helper. The loose (HEREDOC/quoted) fallback parser now
  captures positional args for force-push target detection.
- 33 new tests (10 force-push, 9 secrets, 13 destructive, 1 dry-run).
  Test suite now at 50 tests, all green under
  `APPWRITE_HOOKS_NO_METRICS=1`.

### Changed
- `block()`, `allow()`, `skip()` signatures now take `hook` and
  `tool` parameters so every decision is attributable in the
  metrics log. Existing hooks migrated to the new signatures with
  no behaviour change.
- `hooks.json` registers two matchers instead of one:
  `PreToolUse.Bash` for the 6 Bash guards, `PreToolUse.Edit|Write|MultiEdit`
  for `secrets_guard`.

## [0.1.0] — 2026-04-11

### Added
- Initial release with 4 commit-time guards:
  - `no_verify_guard_hook.py` — blocks `--no-verify` and `--amend`
  - `conventional_commit_hook.py` — enforces `(type): subject` format
  - `regression_test_hook.py` — `(fix):` commits require a staged test
  - `format_lint_hook.py` — runs composer/ktlint/cargo/prettier/ruff
- Shared `_shared.py` with `extract_git_commit`, `_loose_parse`,
  `extract_commit_message`, `has_flag`, `block`, `allow`
- 17 unit tests covering happy paths and parser edge cases
  (HEREDOC messages, chained commands, non-Bash tools)
- Opt-out env vars:
  - `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1`
  - `APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1`
  - `APPWRITE_HOOKS_SKIP_LINT=1`
