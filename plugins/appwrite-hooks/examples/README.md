# appwrite-hooks examples

Sample hook inputs that exercise each guard, with the expected verdict.
These double as documentation for what the hooks catch and as a
regression corpus for the test suite.

Every example is a JSON stdin payload in the shape Claude Code sends:

```json
{"tool_name": "<tool>", "tool_input": {...}}
```

## Running an example manually

```bash
cat examples/commit-ok.json | APPWRITE_HOOKS_NO_METRICS=1 \
  python3 hooks/conventional_commit_hook.py
echo "exit: $?"
```

Exit 0 = allowed, exit 2 = blocked.

## Example catalogue

### `commit-ok.json` — conventional commit, allowed
```json
{"tool_name": "Bash", "tool_input": {"command": "git commit -m \"(feat): add gitea webhook handler\""}}
```
Verdict: **allowed** by all commit guards.

### `commit-missing-type.json` — no type prefix, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "git commit -m \"add a thing\""}}
```
Verdict: **blocked** by `conventional_commit_hook` — message does
not match `(type): subject`.

### `commit-fix-no-test.json` — fix without staged test, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "git commit -m \"(fix): null billing plan crash\""}}
```
Verdict: **blocked** by `regression_test_hook` — the commit is a
`(fix):` but no test file is staged. Override with
`APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1` for doc typos
mislabeled as `(fix):`.

### `commit-no-verify.json` — skipping hooks, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "git commit --no-verify -m \"(feat): x\""}}
```
Verdict: **blocked** by `no_verify_guard_hook`.

### `commit-amend.json` — rewriting history, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "git commit --amend -m \"(fix): y\""}}
```
Verdict: **blocked** by `no_verify_guard_hook`.

### `push-force-main.json` — force-push to main, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "git push --force origin main"}}
```
Verdict: **blocked** by `force_push_guard_hook`. Override with
`APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1`.

### `push-force-version-branch.json` — force-push to 1.9.x, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "git push --force-with-lease origin 1.9.x"}}
```
Verdict: **blocked** — matches the `\d+\.\d+\.x` pattern.

### `push-force-feature.json` — force-push to feature branch, allowed
```json
{"tool_name": "Bash", "tool_input": {"command": "git push --force origin feat-foo"}}
```
Verdict: **allowed** — force-push to your own feature branch is
normal iteration workflow.

### `rm-rf-home.json` — destructive, blocked
```json
{"tool_name": "Bash", "tool_input": {"command": "rm -rf ~"}}
```
Verdict: **blocked** by `destructive_guard_hook`.

### `rm-rf-node-modules.json` — scratch cleanup, allowed
```json
{"tool_name": "Bash", "tool_input": {"command": "rm -rf node_modules"}}
```
Verdict: **allowed** — `node_modules` is in the safe-prefix list.

### `edit-env.json` — writing a .env file, blocked
```json
{"tool_name": "Write", "tool_input": {"file_path": "/repo/.env", "content": "DB_URL=..."}}
```
Verdict: **blocked** by `secrets_guard_hook` — `.env` matches the
secret-path pattern.

### `edit-env-example.json` — writing .env.example, allowed
```json
{"tool_name": "Write", "tool_input": {"file_path": "/repo/.env.example", "content": "DB_URL=postgres://..."}}
```
Verdict: **allowed** — `.env.example` is in the path allowlist.

### `write-aws-key.json` — content contains secret, blocked
```json
{"tool_name": "Write", "tool_input": {"file_path": "/repo/src/config.py", "content": "AWS_KEY = \"AKIAIOSFODNN7EXAMPLE\""}}
```
Verdict: **blocked** by `secrets_guard_hook` — content matches the
AWS access key ID regex.

## Running them all

The `test_hooks.py` test suite covers all of the above plus edge
cases. For a quick round-trip of all examples as real hook runs:

```bash
cd plugins/appwrite-hooks
APPWRITE_HOOKS_NO_METRICS=1 python3 hooks/test_hooks.py
```

50 tests, all green.
