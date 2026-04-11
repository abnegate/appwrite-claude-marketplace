#!/usr/bin/env python3
"""Smoke tests for the commit guard hooks.

Run with: python3 test_hooks.py

Each hook is exercised as a subprocess the way Claude Code runs it:
  - JSON payload on stdin
  - exit 0 = allow, exit 2 = block

Tests cover the happy paths and the edge cases the shared parser has to
survive (HEREDOC messages, chained commands, flag detection).
"""

import json
import subprocess
import sys
import unittest
from pathlib import Path

HOOK_DIR = Path(__file__).parent


def call_hook(hook_name: str, tool_input: dict, tool_name: str = 'Bash') -> tuple[int, str]:
    payload = json.dumps({'tool_name': tool_name, 'tool_input': tool_input})
    result = subprocess.run(
        ['python3', str(HOOK_DIR / hook_name)],
        input=payload,
        capture_output=True,
        text=True,
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
        import os

        env = dict(os.environ, APPWRITE_HOOKS_SKIP_LINT='1')
        result = subprocess.run(
            ['python3', str(HOOK_DIR / self.HOOK)],
            input=json.dumps({'tool_name': 'Bash', 'tool_input': {'command': 'git commit -m "(feat): x"'}}),
            capture_output=True,
            text=True,
            env=env,
            timeout=10,
        )
        self.assertEqual(result.returncode, 0)

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


if __name__ == '__main__':
    unittest.main(verbosity=2)
