#!/usr/bin/env python3
"""Block `git commit --no-verify` and `git commit --amend` unless opted in.

The global CLAUDE.md rules forbid both flags without explicit user request:
  - `--no-verify` skips pre-commit hooks, which defeats the point of the
    format/lint/test guards this plugin is enforcing.
  - `--amend` rewrites the previous commit, which is destructive when a hook
    failed (the previous commit is not the broken one — a new commit is
    needed).

Opt-in escape hatch: set `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1` in the
environment before running the commit. That's intentional friction: the user
has to consciously override the guard.
"""

import os
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import allow, block, extract_git_commit, has_flag, read_tool_input, skip

HOOK = 'no_verify_guard'


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)
        return

    command = tool_input.get('command', '')
    argv = extract_git_commit(command)
    if argv is None:
        skip(HOOK, tool_name)
        return

    if os.environ.get('APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT') == '1':
        allow(HOOK, tool_name, 'opt-out-unsafe-commit')
        return

    if has_flag(argv, '--no-verify', '-n'):
        block(
            HOOK,
            tool_name,
            'BLOCKED: `git commit --no-verify` skips the format/lint/test '
            'guards that exist for a reason. If a hook is failing, fix the '
            'underlying issue — do not bypass the gate.\n\n'
            'If the user has explicitly authorized bypassing hooks, set '
            '`APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1` for the command.',
            reason='no-verify flag',
        )

    if has_flag(argv, '--amend'):
        block(
            HOOK,
            tool_name,
            'BLOCKED: `git commit --amend` rewrites the previous commit. If '
            'a pre-commit hook failed, the previous commit is the WRONG '
            'commit to amend — create a new commit instead. If the user has '
            'explicitly asked for an amend, set '
            '`APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1` for the command.',
            reason='amend flag',
        )

    allow(HOOK, tool_name)


if __name__ == '__main__':
    main()
