#!/usr/bin/env python3
"""Require a staged test file when committing a `(fix):` change.

The global rule is: "Every bug fix must include a regression test that fails
without the fix and passes with it." This hook enforces half of that at the
commit boundary — it checks that at least one file matching a test pattern
is staged. It can't verify that the test actually fails without the fix, but
a missing test file is caught immediately.

Escape hatch: `APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1` overrides.
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
    read_tool_input,
    skip,
    staged_files,
)

HOOK = 'regression_test'

FIX_PREFIX = re.compile(r'^\(fix\): ', re.IGNORECASE)

TEST_PATH_PATTERNS = (
    re.compile(r'(^|/)tests?/'),
    re.compile(r'Test\.(php|kt|java|cs|scala|groovy)$'),
    re.compile(r'_test\.(go|py|rb|ts|tsx|js|jsx)$'),
    re.compile(r'\.spec\.(ts|tsx|js|jsx)$'),
    re.compile(r'_spec\.(rb|py)$'),
    re.compile(r'(^|/)__tests__/'),
    re.compile(r'(^|/)spec/'),
)


def is_test_path(path: str) -> bool:
    return any(pattern.search(path) for pattern in TEST_PATH_PATTERNS)


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)
        return

    argv = extract_git_commit(tool_input.get('command', ''))
    if argv is None:
        skip(HOOK, tool_name)
        return

    message = extract_commit_message(argv)
    if not message:
        skip(HOOK, tool_name)
        return

    first_line = message.strip().splitlines()[0] if message.strip() else ''
    if not FIX_PREFIX.match(first_line):
        skip(HOOK, tool_name)
        return

    if os.environ.get('APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST') == '1':
        allow(HOOK, tool_name, 'opt-out-allow-fix-without-test')
        return

    cwd = tool_input.get('cwd', '')
    files = staged_files(cwd)
    if not files:
        # Nothing staged — git will reject the commit on its own.
        skip(HOOK, tool_name)
        return

    if any(is_test_path(f) for f in files):
        allow(HOOK, tool_name, 'test file staged')
        return

    preview = '\n  '.join(f'  {f}' for f in files[:10])
    if len(files) > 10:
        preview += f'\n  ... and {len(files) - 10} more'

    block(
        HOOK,
        tool_name,
        f'BLOCKED: `(fix):` commit with no regression test staged.\n\n'
        f'Global rule: "Every bug fix must include a regression test that '
        f'fails without the fix and passes with it."\n\n'
        f'Staged files:\n{preview}\n\n'
        f'Add a test that reproduces the bug, stage it, and retry. If this '
        f'genuinely does not need a test (e.g. doc typo fix — should '
        f'probably be `(docs):` instead), override with '
        f'`APPWRITE_HOOKS_ALLOW_FIX_WITHOUT_TEST=1`.',
        reason='fix commit with no test staged',
    )


if __name__ == '__main__':
    main()
