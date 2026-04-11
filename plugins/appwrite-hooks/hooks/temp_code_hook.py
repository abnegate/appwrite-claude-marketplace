#!/usr/bin/env python3
"""Warn about TEMP / HACK / FIXME markers in staged code.

The Appwrite git history shows a pattern of TEMP-prefixed commits
("TEMP: only run 14 failing MongoDB shared tests", "TEMP: skip
non-essential jobs") that get merged and then require follow-up
cleanup commits. This hook scans staged additions for common
temporary-code markers and blocks until they're removed.

Detected markers (case-insensitive):
  - TEMP:, TEMP -, TEMP/
  - HACK:, HACK -
  - FIXME:, FIXME -
  - XXX:, XXX -
  - DO NOT MERGE, DO NOT COMMIT, DO NOT SHIP
  - console.log (in .ts/.tsx/.js/.jsx/.svelte files — debug logging)

The @todo marker is NOT flagged because the Appwrite Console team
uses it as a legitimate tech-debt annotation (per their AGENTS.md).

Escape hatch: APPWRITE_HOOKS_ALLOW_TEMP_CODE=1
"""

import os
import re
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import (
    allow,
    block,
    extract_git_commit,
    read_tool_input,
    skip,
)

HOOK = 'temp_code'

TEMP_MARKERS = re.compile(
    r'(TEMP[\s:/-]|HACK[\s:/-]|FIXME[\s:/-]|XXX[\s:/-]|'
    r'DO\s+NOT\s+MERGE|DO\s+NOT\s+COMMIT|DO\s+NOT\s+SHIP)',
    re.IGNORECASE,
)

# console.log is only flagged in JS/TS/Svelte files — it's a debug
# artifact in frontend code, not in PHP where echo/print serve
# different purposes.
CONSOLE_LOG_PATTERN = re.compile(r'console\.log\s*\(')
CONSOLE_LOG_EXTENSIONS = ('.ts', '.tsx', '.js', '.jsx', '.svelte', '.mjs', '.cjs')

# Files where these markers are expected (this hook, tests, docs).
EXEMPT_PATTERNS = (
    re.compile(r'\.(md|txt|rst)$', re.IGNORECASE),
    re.compile(r'temp_code_hook\.py$'),
    re.compile(r'test_hooks\.py$'),
    re.compile(r'CHANGELOG\.md$'),
)


def staged_diff(cwd: str) -> str:
    try:
        result = subprocess.run(
            ['git', 'diff', '--cached', '--diff-filter=ACMR', '-U0'],
            cwd=cwd or None,
            capture_output=True,
            text=True,
            timeout=10,
        )
    except (subprocess.SubprocessError, FileNotFoundError):
        return ''
    if result.returncode != 0:
        return ''
    return result.stdout


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)

    command = tool_input.get('command', '')
    argv = extract_git_commit(command)
    if argv is None:
        skip(HOOK, tool_name)

    if os.environ.get('APPWRITE_HOOKS_ALLOW_TEMP_CODE') == '1':
        allow(HOOK, tool_name, 'opt-out-allow-temp-code')
        return

    cwd = tool_input.get('cwd', '')
    diff = staged_diff(cwd)
    if not diff:
        skip(HOOK, tool_name)

    findings: list[str] = []
    current_file = ''
    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            current_file = line[6:]
        elif line.startswith('+') and not line.startswith('+++'):
            if any(p.search(current_file) for p in EXEMPT_PATTERNS):
                continue
            added = line[1:]
            if TEMP_MARKERS.search(added):
                findings.append(f'  {current_file}: {added.strip()[:80]}')
            elif CONSOLE_LOG_PATTERN.search(added) and current_file.endswith(CONSOLE_LOG_EXTENSIONS):
                findings.append(f'  {current_file}: console.log — {added.strip()[:60]}')

    if not findings:
        allow(HOOK, tool_name)
        return

    preview = '\n'.join(findings[:10])
    if len(findings) > 10:
        preview += f'\n  ... and {len(findings) - 10} more'

    block(
        HOOK,
        tool_name,
        f'BLOCKED: staged code contains temporary/debug markers.\n\n'
        f'{preview}\n\n'
        f'Remove TEMP/HACK/FIXME markers and console.log calls before '
        f'committing. These are meant for local iteration, not for the '
        f'commit history.\n\n'
        f'Use @todo for legitimate tech-debt annotations (those are not '
        f'flagged by this hook).\n\n'
        f'Override: APPWRITE_HOOKS_ALLOW_TEMP_CODE=1',
        reason=f'{len(findings)} temp markers found',
    )


if __name__ == '__main__':
    main()
