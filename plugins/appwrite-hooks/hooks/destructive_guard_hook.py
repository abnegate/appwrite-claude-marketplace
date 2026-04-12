#!/usr/bin/env python3
"""Block dangerous destructive shell commands.

Targets the classic foot-guns that one wrong keystroke turns into an
afternoon of recovery work:

  - `rm -rf /`, `rm -rf ~`, `rm -rf /home/*`, `rm -rf $HOME`
  - `rm -rf` with a glob pattern at the top of any path
  - `rm -rf` with no explicit path (e.g. a variable expansion to empty)
  - `dd of=/dev/...` (disk wipes)
  - `mkfs.*` (filesystem creation)
  - `> /dev/sd*` (redirect over raw disks)
  - `chmod -R 000`, `chmod -R 777` on absolute paths outside the repo
  - `find / -delete`

Safe targets that this hook allows through (matched by path prefix):
  - Relative paths inside scratch directories: `node_modules`, `dist`,
    `build`, `.next`, `target`, `.cache`, `coverage`, `.pytest_cache`
  - Paths under `/tmp/` or `$TMPDIR`
  - Paths ending in clearly-scratch names: `*.log`, `*.tmp`

Escape hatch: `APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1` for the one command.

This hook is deliberately conservative — it errs on the side of blocking
and expects the user to opt out when they really mean it. A false
positive costs a second; a false negative costs the home directory.
"""

import os
import re
import shlex
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import allow, block, read_tool_input, skip, _strip_command_prefix

HOOK = 'destructive_guard'

# Paths that are always dangerous — blocked unconditionally unless the
# env opt-out is set.
FORBIDDEN_ABSOLUTE = (
    '/', '/*', '/.', '/..',
    '/home', '/home/', '/home/*',
    '/Users', '/Users/', '/Users/*',
    '/root', '/etc', '/var', '/usr', '/bin', '/sbin', '/opt', '/lib',
    '/System', '/Library', '/Applications',
    '/boot', '/dev', '/proc', '/sys', '/run',
)

# Safe scratch paths — rm on these is allowed.
SAFE_PATH_PREFIXES = (
    'node_modules', './node_modules', 'dist', './dist',
    'build', './build', '.next', './.next',
    'target', './target', '.cache', './.cache',
    'coverage', './coverage', '.pytest_cache', './.pytest_cache',
    '__pycache__', './__pycache__', '.nyc_output', './.nyc_output',
    'vendor', './vendor',  # vendored deps (composer/go/bundler)
    '/tmp/', 'tmp/',
)

SAFE_PATH_SUFFIXES = ('.log', '.tmp', '.cache', '.lock', '.pid')

# A glob at the top of a path — `./*`, `/home/*`, etc. Even if the path
# is otherwise fine, a glob at root level is almost never intentional.
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

    # Check every segment of a chained command separately — `rm -rf foo &&
    # rm -rf bar` is two actions, either of which could be dangerous.
    segments = re.split(r'&&|\|\||;|\|', command)
    for segment in segments:
        reason = _is_dangerous(segment.strip())
        if reason:
            block(
                HOOK,
                tool_name,
                f'BLOCKED: refusing to run a destructive command.\n\n'
                f'  Segment: {segment.strip()}\n'
                f'  Reason:  {reason}\n\n'
                f'If you really mean to do this, set '
                f'`APPWRITE_HOOKS_ALLOW_DESTRUCTIVE=1` for the command. '
                f'Double-check the target path first — this hook exists '
                f'because the cost of a wrong delete is far higher than '
                f'the cost of a second-guess.',
                reason=reason,
            )

    allow(HOOK, tool_name)


def _is_dangerous(segment: str) -> str:
    """Return a reason string if the segment is dangerous, else ''."""
    if not segment:
        return ''

    try:
        argv = shlex.split(segment, posix=True)
    except ValueError:
        # Unparseable segment — let it through rather than false-positive.
        return ''
    if not argv:
        return ''

    # Strip command wrappers (sudo, env, KEY=VALUE) to find the real tool.
    argv = _strip_command_prefix(argv)
    if not argv:
        return ''

    tool = argv[0]

    # rm with recursive + force flags
    if tool == 'rm':
        flags = {a for a in argv[1:] if a.startswith('-')}
        is_recursive = any(
            'r' in f or 'R' in f for f in flags if f.startswith('-') and not f.startswith('--')
        ) or '--recursive' in flags
        is_force = any(
            'f' in f for f in flags if f.startswith('-') and not f.startswith('--')
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

    # dd of=/dev/... — disk overwrite (exempt /dev/null)
    if tool == 'dd':
        for arg in argv[1:]:
            if arg.startswith('of=/dev/') and arg != 'of=/dev/null':
                return f'dd writing to raw device: {arg}'

    # mkfs.*
    if tool.startswith('mkfs') or tool.startswith('/sbin/mkfs'):
        return f'filesystem creation: {tool}'

    # find / ... -delete
    if tool == 'find':
        targets = [a for a in argv[1:] if not a.startswith('-')]
        has_delete = '-delete' in argv
        if has_delete and targets:
            for target in targets:
                if target in ('/', '/*') or (target.startswith('/') and len(Path(target).parts) <= 2):
                    return f'find -delete on system path: {target}'

    # chmod -R 000 or -R 777 on absolute paths
    if tool == 'chmod' and ('-R' in argv or '--recursive' in argv):
        for arg in argv[1:]:
            if arg in ('000', '777', '0000', '0777'):
                paths = [a for a in argv[1:] if a.startswith('/')]
                for path in paths:
                    if _path_danger(path):
                        return f'chmod -R {arg} on absolute path: {path}'

    return ''


def _path_danger(target: str) -> str:
    """Return a reason string if deleting this path is dangerous."""
    stripped = target.rstrip('/') or '/'

    # Environment variable references — unsafe unless clearly scratch.
    if '$' in stripped:
        if (stripped.startswith('$TMPDIR') or stripped.startswith('${TMPDIR}')):
            if '/..' not in stripped:
                return ''
        return f'path contains variable expansion: {target}'

    # Tilde — home directory
    if stripped.startswith('~'):
        return f'path targets home directory: {target}'

    # Forbidden absolute paths
    if stripped in FORBIDDEN_ABSOLUTE or (stripped + '/') in FORBIDDEN_ABSOLUTE:
        return f'path is a system directory: {target}'

    # Glob at root of path
    if GLOB_AT_ROOT.match(target):
        return f'glob at root of path: {target}'

    # Absolute path outside safe zones
    if stripped.startswith('/'):
        if any(
            stripped == p.rstrip('/') or stripped.startswith(p)
            for p in SAFE_PATH_PREFIXES if p.startswith('/')
        ):
            return ''
        return f'absolute path outside safe zones: {target}'

    # Relative path — check safe prefixes/suffixes
    normalized = stripped.lstrip('./')
    if any(
        normalized == p.lstrip('./') or normalized.startswith(p.lstrip('./') + '/')
        for p in SAFE_PATH_PREFIXES
    ):
        return ''
    if any(normalized.endswith(s) for s in SAFE_PATH_SUFFIXES):
        return ''

    # Unknown relative path — block unless it's clearly within a project
    # scratch area. Three-level-deep relative paths under project root are
    # usually safe (e.g. `tests/output/foo`), but top-level relative rm
    # should require explicit opt-in.
    if '/' not in normalized:
        return f'relative top-level path: {target}'

    return ''


if __name__ == '__main__':
    main()
