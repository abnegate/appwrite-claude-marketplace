#!/usr/bin/env python3
"""Scan staged diffs for conflict markers and temporary-code markers.

Two scans in one hook (one `git diff --cached` call instead of two):

1. **Conflict markers** — `<<<<<<<`, `=======`, `>>>>>>>` left from
   incomplete merge resolution. No override; markers must never be
   committed.

2. **Temp-code markers** — `TEMP:`, `HACK:`, `FIXME:`, `XXX:`,
   `DO NOT MERGE/COMMIT/SHIP`, and `console.log` in JS/TS/Svelte
   files.  Override: `APPWRITE_HOOKS_ALLOW_TEMP_CODE=1`.

Files exempt from both scans: `.md`, `.txt`, `.rst`, this hook, and
the test file. `CHANGELOG.md` is additionally exempt from temp-code
scanning.
"""

import os
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

HOOK = 'staged_diff_scan'

CONFLICT_PATTERN = re.compile(r'^(<{7}|={7}|>{7})(\s|$)', re.MULTILINE)

TEMP_MARKERS = re.compile(
    r'(TEMP[\s:/-]|HACK[\s:/-]|FIXME[\s:/-]|XXX[\s:/-]|'
    r'DO\s+NOT\s+MERGE|DO\s+NOT\s+COMMIT|DO\s+NOT\s+SHIP)',
    re.IGNORECASE,
)
CONSOLE_LOG_PATTERN = re.compile(r'console\.log\s*\(')
CONSOLE_LOG_EXTENSIONS = ('.ts', '.tsx', '.js', '.jsx', '.svelte', '.mjs', '.cjs')

EXEMPT_ALL = (
    re.compile(r'\.(md|txt|rst)$', re.IGNORECASE),
    re.compile(r'staged_diff_scan_hook\.py$'),
    re.compile(r'test_hooks\.py$'),
)
EXEMPT_TEMP_EXTRA = (
    re.compile(r'CHANGELOG\.md$'),
)


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)
        return

    argv = extract_git_commit(tool_input.get('command', ''))
    if argv is None:
        skip(HOOK, tool_name)
        return

    cwd = tool_input.get('cwd', '')
    diff = staged_diff(cwd)
    if not diff:
        skip(HOOK, tool_name)
        return

    skip_temp = os.environ.get('APPWRITE_HOOKS_ALLOW_TEMP_CODE') == '1'

    conflict_findings: list[str] = []
    temp_findings: list[str] = []
    current_file = ''

    for line in diff.splitlines():
        if line.startswith('+++ b/'):
            current_file = line[6:]
            continue
        if not line.startswith('+') or line.startswith('+++'):
            continue

        added = line[1:]
        exempt_all = any(p.search(current_file) for p in EXEMPT_ALL)

        # Conflict markers — never skippable, only exempt in docs/tests.
        if not exempt_all and CONFLICT_PATTERN.match(added):
            conflict_findings.append(f'  {current_file}: {added.strip()[:80]}')

        # Temp-code markers — skippable via env, extra exemptions.
        if not skip_temp and not exempt_all:
            exempt_temp = any(p.search(current_file) for p in EXEMPT_TEMP_EXTRA)
            if not exempt_temp:
                if TEMP_MARKERS.search(added):
                    temp_findings.append(f'  {current_file}: {added.strip()[:80]}')
                elif (
                    CONSOLE_LOG_PATTERN.search(added)
                    and current_file.endswith(CONSOLE_LOG_EXTENSIONS)
                ):
                    temp_findings.append(
                        f'  {current_file}: console.log — {added.strip()[:60]}'
                    )

    # Conflict markers block first (no override).
    if conflict_findings:
        preview = _preview(conflict_findings)
        block(
            HOOK,
            tool_name,
            f'BLOCKED: staged files contain unresolved merge conflict markers.\n\n'
            f'{preview}\n\n'
            f'Resolve all conflicts and remove the markers before committing.\n'
            f'This hook has no override — conflict markers must never be committed.',
            reason=f'{len(conflict_findings)} conflict markers found',
        )
        return

    if temp_findings:
        preview = _preview(temp_findings)
        block(
            HOOK,
            tool_name,
            f'BLOCKED: staged code contains temporary/debug markers.\n\n'
            f'{preview}\n\n'
            f'Remove TEMP/HACK/FIXME markers and console.log calls before '
            f'committing. Use @todo for legitimate tech-debt annotations.\n\n'
            f'Override: APPWRITE_HOOKS_ALLOW_TEMP_CODE=1',
            reason=f'{len(temp_findings)} temp markers found',
        )
        return

    allow(HOOK, tool_name)


def _preview(findings: list[str]) -> str:
    preview = '\n'.join(findings[:10])
    if len(findings) > 10:
        preview += f'\n  ... and {len(findings) - 10} more'
    return preview


if __name__ == '__main__':
    main()
