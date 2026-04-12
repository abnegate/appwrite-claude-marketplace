#!/usr/bin/env python3
"""Enforce commit discipline: no --no-verify/--amend, conventional format.

Combines two checks that both parse `git commit` argv:

1. **Flag guard** — blocks `--no-verify` (bypasses hooks) and `--amend`
   (rewrites previous commit, dangerous after hook failure). Override:
   `APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1`.

2. **Message format** — requires `(type): subject` with types in {feat,
   fix, refactor, chore, docs, test, style, perf, revert, ci, cleanup,
   improvement, build}. Merge/revert commits are exempt.
"""

import os
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import (
    allow,
    block,
    extract_commit_message,
    extract_git_commit,
    has_flag,
    read_tool_input,
    skip,
)

HOOK = 'conventional_commit'

ALLOWED_TYPES = (
    'feat',
    'fix',
    'refactor',
    'chore',
    'docs',
    'test',
    'style',
    'perf',
    'revert',
    'ci',
    'cleanup',
    'improvement',
    'build',
)

CONVENTIONAL = re.compile(
    rf"^\(({'|'.join(ALLOWED_TYPES)})\): .+",
    re.IGNORECASE,
)
MERGE = re.compile(r'^(Merge|Revert) ', re.IGNORECASE)


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)
        return

    argv = extract_git_commit(tool_input.get('command', ''))
    if argv is None:
        skip(HOOK, tool_name)
        return

    # Flag guard — check before message format so --no-verify is caught
    # even on well-formatted commits.
    if os.environ.get('APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT') != '1':
        if has_flag(argv, '--no-verify', '-n'):
            block(
                HOOK,
                tool_name,
                'BLOCKED: `git commit --no-verify` skips the format/lint/test '
                'guards. Fix the underlying issue instead of bypassing.\n\n'
                'Override: APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1',
                reason='no-verify flag',
            )
            return
        if has_flag(argv, '--amend'):
            block(
                HOOK,
                tool_name,
                'BLOCKED: `git commit --amend` rewrites the previous commit. '
                'After a hook failure, create a new commit instead.\n\n'
                'Override: APPWRITE_HOOKS_ALLOW_UNSAFE_COMMIT=1',
                reason='amend flag',
            )
            return

    message = extract_commit_message(argv)
    if message is None:
        # No -m flag means git will open $EDITOR. That's fine for interactive
        # use, but Claude Code can't drive an editor — let it fail naturally
        # instead of blocking here.
        skip(HOOK, tool_name)
        return

    first_line = message.strip().splitlines()[0] if message.strip() else ''
    if not first_line:
        block(
            HOOK,
            tool_name,
            'BLOCKED: empty commit message. Use `(type): subject` format, '
            f'where type is one of: {", ".join(ALLOWED_TYPES)}.',
            reason='empty message',
        )
        return

    if MERGE.match(first_line) or CONVENTIONAL.match(first_line):
        allow(HOOK, tool_name)
        return

    block(
        HOOK,
        tool_name,
        f'BLOCKED: commit message does not follow conventional format.\n\n'
        f'  Got:      {first_line!r}\n'
        f'  Expected: (type): subject\n'
        f'  Types:    {", ".join(ALLOWED_TYPES)}\n\n'
        f'Examples:\n'
        f'  (feat): add gitea webhook handler\n'
        f'  (fix): guard against null billing plan cache\n'
        f'  (refactor): extract publisher resource wiring\n\n'
        f'Rewrite the commit message and retry.',
        reason='non-conventional format',
    )


if __name__ == '__main__':
    main()
