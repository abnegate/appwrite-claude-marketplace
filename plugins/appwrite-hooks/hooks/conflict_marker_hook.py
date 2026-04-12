#!/usr/bin/env python3
"""Block commits that contain unresolved merge conflict markers.

50+ bug-fix commits across the Appwrite ecosystem traced back to leftover
conflict markers (`<<<<<<<`, `=======`, `>>>>>>>`) that slipped through
merge resolution. This hook greps every staged file for these markers and
blocks the commit if any are found.

This is one of the highest-leverage hooks in the suite — a single grep
prevents an entire class of post-merge breakage that otherwise requires
a follow-up fix commit.

No escape hatch — there is no legitimate reason to commit a conflict
marker. If you're writing documentation about conflict markers, quote
them differently (e.g. use backtick fences).
"""

import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import (
    allow,
    block,
    extract_git_commit,
    read_tool_input,
    skip,
    staged_diff,
)

HOOK = 'conflict_marker'

CONFLICT_PATTERN = re.compile(r'^(<{7}|={7}|>{7})(\s|$)', re.MULTILINE)

# Files where conflict markers are expected content (docs, tests, this hook itself).
EXEMPT_PATTERNS = (
    re.compile(r'\.(md|txt|rst)$', re.IGNORECASE),
    re.compile(r'conflict_marker_hook\.py$'),
    re.compile(r'test_hooks\.py$'),
)


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

    cwd = tool_input.get('cwd', '')
    diff = staged_diff(cwd)
    if not diff:
        skip(HOOK, tool_name)
        return

    findings: list[str] = []
    current_file = ''
    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            current_file = line[6:]
        elif line.startswith('+') and not line.startswith('+++'):
            added = line[1:]
            if CONFLICT_PATTERN.match(added):
                if any(p.search(current_file) for p in EXEMPT_PATTERNS):
                    continue
                findings.append(f'  {current_file}: {added.strip()[:80]}')

    if not findings:
        allow(HOOK, tool_name)
        return

    preview = '\n'.join(findings[:10])
    if len(findings) > 10:
        preview += f'\n  ... and {len(findings) - 10} more'

    block(
        HOOK,
        tool_name,
        f'BLOCKED: staged files contain unresolved merge conflict markers.\n\n'
        f'{preview}\n\n'
        f'Resolve all conflicts and remove the markers before committing.\n'
        f'This hook has no override — conflict markers must never be committed.',
        reason=f'{len(findings)} conflict markers found',
    )


if __name__ == '__main__':
    main()
