#!/usr/bin/env python3
"""Block `rm -rf` on system and home directories.

Catches the highest-impact foot-gun: recursive force-delete of paths
that are unrecoverable. Allows `rm -rf` on known scratch targets
(node_modules, dist, build, /tmp/, etc.) and deep relative paths.

Escape hatch: `APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1` for the one command.
"""

import os
import re
import shlex
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import allow, block, read_tool_input, skip, _strip_command_prefix

HOOK = 'destructive_guard'

FORBIDDEN_ABSOLUTE = frozenset((
    '/', '/*', '/.', '/..',
    '/home', '/home/', '/home/*',
    '/Users', '/Users/', '/Users/*',
    '/root', '/etc', '/var', '/usr', '/bin', '/sbin', '/opt', '/lib',
    '/System', '/Library', '/Applications',
    '/boot', '/dev', '/proc', '/sys', '/run',
))

SAFE_PATH_PREFIXES = (
    'node_modules', './node_modules', 'dist', './dist',
    'build', './build', '.next', './.next',
    'target', './target', '.cache', './.cache',
    'coverage', './coverage', '.pytest_cache', './.pytest_cache',
    '__pycache__', './__pycache__', '.nyc_output', './.nyc_output',
    'vendor', './vendor',
    '/tmp/', 'tmp/',
)

SAFE_PATH_SUFFIXES = ('.log', '.tmp', '.cache', '.lock', '.pid')

GLOB_AT_ROOT = re.compile(r'^(?:\.\/|/)?(\*|\*/)')


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)
        return

    command = (tool_input.get('command') or '').strip()
    if not command:
        skip(HOOK, tool_name)
        return

    if os.environ.get('APPWRITE_HOOKS_ALLOW_DESTRUCTIVE') == '1':
        allow(HOOK, tool_name, 'opt-out-allow-destructive')
        return

    for segment in re.split(r'&&|\|\||;|\|', command):
        reason = _check_rm_rf(segment.strip())
        if reason:
            block(
                HOOK,
                tool_name,
                f'BLOCKED: refusing to run a destructive command.\n\n'
                f'  Segment: {segment.strip()}\n'
                f'  Reason:  {reason}\n\n'
                f'Override: APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1',
                reason=reason,
            )
            return

    allow(HOOK, tool_name)


def _check_rm_rf(segment: str) -> str:
    """Return a reason string if the segment contains a dangerous rm -rf."""
    if not segment:
        return ''
    try:
        argv = shlex.split(segment, posix=True)
    except ValueError:
        return ''
    if not argv:
        return ''

    argv = _strip_command_prefix(argv)
    if not argv or argv[0] != 'rm':
        return ''

    flags = {a for a in argv[1:] if a.startswith('-')}
    is_recursive = any(
        'r' in f or 'R' in f for f in flags if not f.startswith('--')
    ) or '--recursive' in flags
    is_force = any(
        'f' in f for f in flags if not f.startswith('--')
    ) or '--force' in flags
    if not (is_recursive and is_force):
        return ''

    targets = [a for a in argv[1:] if not a.startswith('-')]
    if not targets:
        return 'rm -rf with no explicit target (possible empty-variable expansion)'

    for target in targets:
        reason = _path_danger(target)
        if reason:
            return reason
    return ''


def _path_danger(target: str) -> str:
    """Return a reason string if deleting this path is dangerous."""
    stripped = target.rstrip('/') or '/'

    # Variable expansion — only allow $TMPDIR without traversal.
    if '$' in stripped:
        if (stripped.startswith('$TMPDIR') or stripped.startswith('${TMPDIR}')):
            if '/..' not in stripped:
                return ''
        return f'path contains variable expansion: {target}'

    if stripped.startswith('~'):
        return f'path targets home directory: {target}'

    if stripped in FORBIDDEN_ABSOLUTE or (stripped + '/') in FORBIDDEN_ABSOLUTE:
        return f'path is a system directory: {target}'

    if GLOB_AT_ROOT.match(target):
        return f'glob at root of path: {target}'

    # Absolute path outside safe zones.
    if stripped.startswith('/'):
        if any(
            stripped == p.rstrip('/') or stripped.startswith(p)
            for p in SAFE_PATH_PREFIXES if p.startswith('/')
        ):
            return ''
        return f'absolute path outside safe zones: {target}'

    # Relative path — check safe prefixes/suffixes.
    normalized = stripped.lstrip('./')
    if any(
        normalized == p.lstrip('./') or normalized.startswith(p.lstrip('./') + '/')
        for p in SAFE_PATH_PREFIXES
    ):
        return ''
    if any(normalized.endswith(s) for s in SAFE_PATH_SUFFIXES):
        return ''

    if '/' not in normalized:
        return f'relative top-level path: {target}'

    return ''


if __name__ == '__main__':
    main()
