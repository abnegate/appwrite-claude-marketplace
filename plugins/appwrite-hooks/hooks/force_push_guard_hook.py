#!/usr/bin/env python3
"""Block force-push to protected branches.

The global CLAUDE.md rules forbid force-pushing to main/master without
explicit user request. This hook catches:
  - `git push --force`         / `-f`
  - `git push --force-with-lease`
  - Targeting any of: main, master, trunk, develop
  - Or a version branch matching `\\d+\\.\\d+\\.x` (e.g. 1.9.x)

Non-force pushes are allowed. Force pushes to non-protected branches
(feature branches, personal forks) are allowed — force-pushing your
own branch is normal workflow.

Escape hatch: `APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1` for the one command.
"""

import os
import re
import sys
from pathlib import Path
from typing import Optional

sys.path.insert(0, str(Path(__file__).parent))
from _shared import (
    allow,
    block,
    extract_git_push,
    has_flag,
    read_tool_input,
    skip,
)

HOOK = 'force_push_guard'

PROTECTED_BRANCH_PATTERNS = (
    re.compile(r'^(main|master|trunk|develop)$'),
    re.compile(r'^\d+\.\d+\.x$'),       # 1.9.x style
    re.compile(r'^release[/\-].+$'),    # release/* or release-*
    re.compile(r'^hotfix[/\-].+$'),
)

FORCE_FLAGS = ('--force', '-f', '--force-with-lease', '--force-if-includes')


def targets_protected_branch(argv: list[str]) -> Optional[str]:
    """Inspect the push argv for a protected branch target.

    Returns the matched branch name, or None if no protected target found.

    Handles common shapes:
      git push                                # no explicit ref -> default, assume protected if HEAD is protected (conservative: block)
      git push origin main                    # positional ref
      git push origin HEAD:main
      git push origin feature-branch:main     # local:remote
      git push origin +main                   # leading + means force
      git push --force origin main
    """
    for index, token in enumerate(argv[2:], start=2):  # skip 'git push'
        if token.startswith('-'):
            continue
        # Positional. First positional is remote; subsequent are refspecs.
        # We care about refspecs, which look like `[+]<local>:<remote>` or
        # just `<remote>` when local == remote.
        if index == 2:  # remote name
            continue
        refspec = token.lstrip('+')
        remote_ref = refspec.split(':', 1)[-1]
        remote_branch = remote_ref.removeprefix('refs/heads/')
        for pattern in PROTECTED_BRANCH_PATTERNS:
            if pattern.match(remote_branch):
                return remote_branch

    # No explicit ref: `git push` with no args pushes the current upstream.
    # We can't know what that is from the hook payload, so we be conservative
    # and only block when a force flag is also present — the protected-branch
    # check here returns None, but main() will still block on the force flag
    # if we can't determine the target.
    return None


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)

    command = tool_input.get('command', '')
    argv = extract_git_push(command)
    if argv is None:
        skip(HOOK, tool_name)

    if os.environ.get('APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH') == '1':
        allow(HOOK, tool_name, 'opt-out-unsafe-push')
        return

    has_force = has_flag(argv, *FORCE_FLAGS)
    # Detect leading + in any refspec (equivalent to --force)
    for token in argv[2:]:
        if not token.startswith('-') and ':' in token and '+' in token.split(':', 1)[0]:
            has_force = True
            break
        if not token.startswith('-') and token.startswith('+'):
            has_force = True
            break

    if not has_force:
        allow(HOOK, tool_name)
        return

    target = targets_protected_branch(argv)
    if target is None and _pushes_without_explicit_target(argv):
        block(
            HOOK,
            tool_name,
            'BLOCKED: force-push with no explicit target. Cannot verify the '
            'current upstream is safe to rewrite. Specify the target branch '
            'explicitly or set APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1 if you are '
            'sure.',
            reason='force-push with implicit target',
        )
    if target is None:
        # Force-pushing an explicit non-protected branch. Allowed.
        allow(HOOK, tool_name, 'force-push to non-protected branch')
        return

    block(
        HOOK,
        tool_name,
        f'BLOCKED: force-push to protected branch `{target}`.\n\n'
        f'The global rules forbid rewriting history on main/master/trunk/develop '
        f'and on version branches like 1.9.x. Force-pushing these breaks every '
        f'other clone that has fetched them.\n\n'
        f'If the user has explicitly authorized this force-push, set '
        f'`APPWRITE_HOOKS_ALLOW_UNSAFE_PUSH=1` for the command.',
        reason=f'force-push to {target}',
    )


def _pushes_without_explicit_target(argv: list[str]) -> bool:
    """True if argv has no positional refspec beyond the remote name."""
    positional = [t for t in argv[2:] if not t.startswith('-')]
    return len(positional) <= 1  # at most the remote name, no refspec


if __name__ == '__main__':
    main()
