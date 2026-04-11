#!/usr/bin/env python3
"""Smoke tests for the appwrite-hooks guard hooks.

Run with: python3 test_hooks.py

Each hook is exercised as a subprocess the way Claude Code runs it:
  - JSON payload on stdin
  - exit 0 = allow, exit 2 = block

Tests cover the happy paths and the edge cases the shared parser has to
survive (HEREDOC messages, chained commands, flag detection).

All tests run with APPWRITE_HOOKS_NO_METRICS=1 so they don't pollute
~/.claude/metrics/appwrite-hooks.jsonl.
"""

import json
import os
import subprocess
import sys
import unittest
from pathlib import Path

HOOK_DIR = Path(__file__).parent
BASE_ENV = dict(os.environ, APPWRITE_HOOKS_NO_METRICS='1')


def call_hook(
    hook_name: str,
    tool_input: dict,
    tool_name: str = 'Bash',
    env_overrides: dict | None = None,
) -> tuple[int, str]:
    payload = json.dumps({'tool_name': tool_name, 'tool_input': tool_input})
    env = dict(BASE_ENV)
    if env_overrides:
        env.update(env_overrides)
    result = subprocess.run(
        ['python3', str(HOOK_DIR / hook_name)],
        input=payload,
        capture_output=True,
        text=True,
        env=env,
        timeout=10,
    )
    return result.returncode, result.stderr


class NoVerifyGuardTests(unittest.TestCase):
    HOOK = 'no_verify_guard_hook.py'

    def test_allows_normal_commit(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git commit -m "(feat): add foo"'})
        self.assertEqual(code, 0)

    def test_blocks_no_verify(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {'command': 'git commit --no-verify -m "(feat): add foo"'},
        )
        self.assertEqual(code, 2)
        self.assertIn('no-verify', err)

    def test_blocks_amend(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {'command': 'git commit --amend -m "(feat): add foo"'},
        )
        self.assertEqual(code, 2)
        self.assertIn('amend', err)

    def test_ignores_non_git_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'ls -la'})
        self.assertEqual(code, 0)

    def test_ignores_non_bash_tools(self) -> None:
        code, _ = call_hook(self.HOOK, {'file_path': '/tmp/x'}, tool_name='Read')
        self.assertEqual(code, 0)

    def test_dry_run_converts_block_to_allow(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {'command': 'git commit --no-verify -m "(feat): add foo"'},
            env_overrides={'APPWRITE_HOOKS_DRY_RUN': '1'},
        )
        self.assertEqual(code, 0)
        self.assertIn('DRY RUN', err)


class ConventionalCommitTests(unittest.TestCase):
    HOOK = 'conventional_commit_hook.py'

    def test_allows_feat(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git commit -m "(feat): add gitea webhook"'})
        self.assertEqual(code, 0)

    def test_allows_fix(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': 'git commit -m "(fix): guard against null billing plan"'},
        )
        self.assertEqual(code, 0)

    def test_allows_merge(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': "git commit -m 'Merge pull request #123 from abc/def'"},
        )
        self.assertEqual(code, 0)

    def test_blocks_missing_type(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git commit -m "add foo"'})
        self.assertEqual(code, 2)
        self.assertIn('conventional format', err)

    def test_blocks_unknown_type(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git commit -m "(wat): add foo"'})
        self.assertEqual(code, 2)
        self.assertIn('conventional format', err)

    def test_blocks_bare_type_no_parens(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git commit -m "feat: add foo"'})
        self.assertEqual(code, 2)
        self.assertIn('conventional format', err)

    def test_allows_chained_commands(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': 'git add . && git commit -m "(feat): chained"'},
        )
        self.assertEqual(code, 0)


class FormatLintIgnoreTests(unittest.TestCase):
    """Format-lint hook should not block when skip-env is set or no files staged."""

    HOOK = 'format_lint_hook.py'

    def test_skipped_by_env(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': 'git commit -m "(feat): x"'},
            env_overrides={'APPWRITE_HOOKS_SKIP_LINT': '1'},
        )
        self.assertEqual(code, 0)

    def test_ignores_non_git_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'echo hello'})
        self.assertEqual(code, 0)


class RegressionTestHookIgnoreTests(unittest.TestCase):
    HOOK = 'regression_test_hook.py'

    def test_allows_non_fix_commit(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git commit -m "(feat): add foo"'})
        self.assertEqual(code, 0)

    def test_allows_merge(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': "git commit -m 'Merge pull request #1 from x/y'"},
        )
        self.assertEqual(code, 0)

    def test_ignores_non_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'file_path': '/tmp/x'}, tool_name='Read')
        self.assertEqual(code, 0)


class ForcePushGuardTests(unittest.TestCase):
    HOOK = 'force_push_guard_hook.py'

    def test_allows_normal_push(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git push origin feat-foo'})
        self.assertEqual(code, 0)

    def test_allows_force_push_to_feature_branch(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git push --force origin feat-foo'})
        self.assertEqual(code, 0)

    def test_blocks_force_push_to_main(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git push --force origin main'})
        self.assertEqual(code, 2)
        self.assertIn('main', err)

    def test_blocks_force_push_to_master(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git push -f origin master'})
        self.assertEqual(code, 2)
        self.assertIn('master', err)

    def test_blocks_force_push_to_version_branch(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git push --force origin 1.9.x'})
        self.assertEqual(code, 2)
        self.assertIn('1.9.x', err)

    def test_blocks_force_with_lease_to_main(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {'command': 'git push --force-with-lease origin main'},
        )
        self.assertEqual(code, 2)

    def test_blocks_leading_plus_refspec_on_main(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git push origin +main'})
        self.assertEqual(code, 2)
        self.assertIn('main', err)

    def test_blocks_force_push_no_target(self) -> None:
        code, err = call_hook(self.HOOK, {'command': 'git push --force'})
        self.assertEqual(code, 2)
        self.assertIn('no explicit target', err)

    def test_opt_out_allows_force_push_to_main(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': 'git push --force origin main'},
            env_overrides={'APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH': '1'},
        )
        self.assertEqual(code, 0)

    def test_ignores_non_push_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git status'})
        self.assertEqual(code, 0)


class SecretsGuardTests(unittest.TestCase):
    HOOK = 'secrets_guard_hook.py'

    def test_blocks_env_write(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {'file_path': '/repo/.env', 'content': 'DB=x'},
            tool_name='Write',
        )
        self.assertEqual(code, 2)
        self.assertIn('.env', err)

    def test_allows_env_example(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'file_path': '/repo/.env.example', 'content': 'DB=example'},
            tool_name='Write',
        )
        self.assertEqual(code, 0)

    def test_blocks_pem_edit(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {'file_path': '/secrets/app.pem', 'new_string': 'abc'},
            tool_name='Edit',
        )
        self.assertEqual(code, 2)

    def test_blocks_id_rsa(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'file_path': '/home/user/.ssh/id_rsa', 'content': 'abc'},
            tool_name='Write',
        )
        self.assertEqual(code, 2)

    def test_blocks_aws_key_in_content(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {
                'file_path': '/repo/src/foo.py',
                'content': 'KEY = "AKIAIOSFODNN7EXAMPLE"',
            },
            tool_name='Write',
        )
        self.assertEqual(code, 2)
        self.assertIn('AWS', err)

    def test_blocks_private_key_in_content(self) -> None:
        code, err = call_hook(
            self.HOOK,
            {
                'file_path': '/repo/src/foo.md',
                'content': 'example\n-----BEGIN RSA PRIVATE KEY-----\n...\n',
            },
            tool_name='Write',
        )
        self.assertEqual(code, 2)
        self.assertIn('private key', err)

    def test_allows_normal_code(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'file_path': '/repo/src/foo.py', 'content': 'print("hi")'},
            tool_name='Write',
        )
        self.assertEqual(code, 0)

    def test_ignores_non_edit_tools(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'ls -la'}, tool_name='Bash')
        self.assertEqual(code, 0)

    def test_opt_out_allows_env_write(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'file_path': '/repo/.env', 'content': 'DB=x'},
            tool_name='Write',
            env_overrides={'APPWRITE_HOOKS_ALLOW_SECRETS': '1'},
        )
        self.assertEqual(code, 0)


class DestructiveGuardTests(unittest.TestCase):
    HOOK = 'destructive_guard_hook.py'

    def test_blocks_rm_rf_slash(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf /'})
        self.assertEqual(code, 2)

    def test_blocks_rm_rf_home(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf ~'})
        self.assertEqual(code, 2)

    def test_blocks_rm_rf_with_variable(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf $SOMETHING'})
        self.assertEqual(code, 2)

    def test_blocks_rm_rf_glob_at_root(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf /*'})
        self.assertEqual(code, 2)

    def test_allows_rm_rf_node_modules(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf node_modules'})
        self.assertEqual(code, 0)

    def test_allows_rm_rf_dist(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf ./dist'})
        self.assertEqual(code, 0)

    def test_allows_rm_rf_tmp(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm -rf /tmp/xyz'})
        self.assertEqual(code, 0)

    def test_allows_plain_rm(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'rm foo.txt'})
        self.assertEqual(code, 0)

    def test_blocks_mkfs(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'mkfs.ext4 /dev/sda1'})
        self.assertEqual(code, 2)

    def test_blocks_find_delete_on_root(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'find / -delete'})
        self.assertEqual(code, 2)

    def test_allows_find_delete_in_scratch(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'find ./build -name "*.tmp" -delete'})
        self.assertEqual(code, 0)

    def test_ignores_non_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'file_path': '/tmp/x'}, tool_name='Read')
        self.assertEqual(code, 0)

    def test_opt_out_allows_rm_rf_home(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': 'rm -rf ~'},
            env_overrides={'APPWRITE_HOOKS_ALLOW_DESTRUCTIVE': '1'},
        )
        self.assertEqual(code, 0)


class ConflictMarkerHookTests(unittest.TestCase):
    """Conflict-marker hook can't fully test without a real git repo, but we
    can verify it ignores non-git and non-Bash calls."""

    HOOK = 'conflict_marker_hook.py'

    def test_ignores_non_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'file_path': '/tmp/x'}, tool_name='Read')
        self.assertEqual(code, 0)

    def test_ignores_non_git_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'echo hello'})
        self.assertEqual(code, 0)

    def test_allows_normal_commit(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git commit -m "(feat): add foo"'})
        self.assertEqual(code, 0)


class TempCodeHookTests(unittest.TestCase):
    """Same limitation as conflict-marker — real diff-based testing needs a
    git repo. Here we verify passthrough and opt-out paths."""

    HOOK = 'temp_code_hook.py'

    def test_ignores_non_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'file_path': '/tmp/x'}, tool_name='Read')
        self.assertEqual(code, 0)

    def test_ignores_non_git_bash(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'echo hello'})
        self.assertEqual(code, 0)

    def test_allows_normal_commit(self) -> None:
        code, _ = call_hook(self.HOOK, {'command': 'git commit -m "(feat): add foo"'})
        self.assertEqual(code, 0)

    def test_opt_out_allows(self) -> None:
        code, _ = call_hook(
            self.HOOK,
            {'command': 'git commit -m "(feat): temp stuff"'},
            env_overrides={'APPWRITE_HOOKS_ALLOW_TEMP_CODE': '1'},
        )
        self.assertEqual(code, 0)


if __name__ == '__main__':
    unittest.main(verbosity=2)
