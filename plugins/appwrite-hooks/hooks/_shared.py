"""Shared helpers for appwrite-hooks PreToolUse guards.

Hook protocol reminder:
  - Read JSON from stdin with tool_name / tool_input
  - Exit 0 to allow
  - Exit 2 with stderr text to block (stderr is surfaced to Claude)

Every hook that uses this module gets:
  - Metric logging to ~/.claude/metrics/appwrite-hooks.jsonl (opt out via
    APPWRITE_HOOKS_NO_METRICS=1). Every decision records tool, hook name,
    verdict (allowed/blocked/would-block), and reason.
  - Dry-run mode via APPWRITE_HOOKS_DRY_RUN=1. When set, hooks log what
    they WOULD have done but exit 0 so nothing actually blocks. Useful
    for probing "what would these hooks catch on my current branch?"
    without changing behaviour.
"""

import json
import os
import re
import shlex
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

METRICS_DIR = Path.home() / '.claude' / 'metrics'
METRICS_FILE = METRICS_DIR / 'appwrite-hooks.jsonl'


def read_tool_input() -> tuple[str, dict]:
    """Parse hook stdin payload. Returns (tool_name, tool_input).

    Any parse failure is treated as "not our problem" — exit 0 and let the
    tool run. A hook that blocks a tool on bad input is worse than a hook
    that misses a check.
    """
    try:
        payload = json.loads(sys.stdin.read())
    except (json.JSONDecodeError, ValueError):
        sys.exit(0)
    return payload.get('tool_name', ''), payload.get('tool_input', {}) or {}


def extract_git_commit(command: str) -> Optional[list[str]]:
    """If command is (or contains) a `git commit`, return the argv list.

    Handles:
      - Plain `git commit -m "..."`
      - HEREDOC wrappers like `git commit -m "$(cat <<'EOF' ... EOF)"`
      - Chained commands like `git add . && git commit -m "..."`

    Returns None if the command isn't a git commit.
    """
    return _extract_git_subcommand(command, 'commit')


def extract_git_push(command: str) -> Optional[list[str]]:
    """If command is (or contains) a `git push`, return the argv list.

    Returns None if the command isn't a git push.
    """
    return _extract_git_subcommand(command, 'push')


def _extract_git_subcommand(command: str, subcommand: str) -> Optional[list[str]]:
    needle = f'git {subcommand}'
    if needle not in command:
        return None

    # Split on command separators (&&, ;, |) and look at each segment.
    segments = re.split(r'&&|\|\||;|\|', command)
    for segment in segments:
        segment = segment.strip()
        if needle not in segment:
            continue
        try:
            argv = shlex.split(segment, posix=True)
        except ValueError:
            return _loose_parse_git(segment, subcommand)
        if len(argv) >= 2 and argv[0] == 'git' and argv[1] == subcommand:
            return argv
    return None


def _loose_parse_git(segment: str, subcommand: str) -> list[str]:
    """Fallback parser for quoted or HEREDOC commands that confuse shlex."""
    argv = ['git', subcommand]
    for flag in ('--no-verify', '--amend', '--force', '--force-with-lease', '-f', '-n'):
        if re.search(rf'(?<!\S){re.escape(flag)}(?!\S)', segment):
            argv.append(flag)
    heredoc = re.search(r"<<\s*'?EOF'?\s*\n(.*?)\nEOF", segment, re.DOTALL)
    if heredoc:
        argv.extend(['-m', heredoc.group(1).strip()])
        return argv
    simple = re.search(r'-m\s+"([^"]*)"', segment)
    if simple:
        argv.extend(['-m', simple.group(1)])
    # Capture positional args (remote + refspec) — best-effort.
    for token in re.findall(r'(?<!\S)([\w./:@\-]+)(?!\S)', segment):
        if token in ('git', subcommand) or token.startswith('-'):
            continue
        if token not in argv:
            argv.append(token)
    return argv


def extract_commit_message(argv: list[str]) -> Optional[str]:
    """Pull the -m message out of a parsed git-commit argv."""
    for index, token in enumerate(argv):
        if token == '-m' and index + 1 < len(argv):
            return argv[index + 1]
        if token.startswith('-m') and len(token) > 2:
            return token[2:]
        if token.startswith('--message='):
            return token.split('=', 1)[1]
    return None


def has_flag(argv: list[str], *flags: str) -> bool:
    """True if any of the given flags appear in argv."""
    argset = set(argv)
    return any(flag in argset for flag in flags)


def log_metric(
    hook: str,
    tool: str,
    verdict: str,
    reason: str = '',
    extra: Optional[dict] = None,
) -> None:
    """Append a single JSONL line to the metrics file. Never raises.

    verdict ∈ {'allowed', 'blocked', 'would-block', 'skipped'}
      - 'allowed'    — hook was applicable and decided to allow
      - 'blocked'    — hook was applicable and decided to block
      - 'would-block'— hook would have blocked but DRY_RUN was set
      - 'skipped'    — hook didn't apply (wrong tool, wrong pattern, etc)
    """
    if os.environ.get('APPWRITE_HOOKS_NO_METRICS') == '1':
        return
    record = {
        'ts': datetime.now(timezone.utc).isoformat(timespec='seconds'),
        'hook': hook,
        'tool': tool,
        'verdict': verdict,
    }
    if reason:
        record['reason'] = reason
    if extra:
        record.update(extra)
    try:
        METRICS_DIR.mkdir(parents=True, exist_ok=True)
        with METRICS_FILE.open('a', encoding='utf-8') as handle:
            handle.write(json.dumps(record) + '\n')
    except (OSError, PermissionError):
        # Metrics are best-effort. A disk/permission failure must never
        # break the hook pipeline — the user's commit is more important
        # than the audit trail.
        pass


def is_dry_run() -> bool:
    return os.environ.get('APPWRITE_HOOKS_DRY_RUN') == '1'


def block(hook: str, tool: str, message: str, reason: str = '') -> None:
    """Block the tool call (or log-and-allow under DRY_RUN).

    Emits the stderr message and exits 2 under normal operation. Under
    DRY_RUN, emits the message to stderr with a [DRY RUN] prefix, logs
    the would-block metric, and exits 0.
    """
    if is_dry_run():
        log_metric(hook, tool, 'would-block', reason or _first_line(message))
        print(f'[DRY RUN — would block]\n{message}', file=sys.stderr)
        sys.exit(0)
    log_metric(hook, tool, 'blocked', reason or _first_line(message))
    print(message, file=sys.stderr)
    sys.exit(2)


def allow(hook: str = '', tool: str = '', reason: str = '') -> None:
    """Allow the tool call. If a hook name is given, log as 'allowed';
    otherwise don't log (the hook decided it wasn't applicable).
    """
    if hook:
        log_metric(hook, tool, 'allowed', reason)
    sys.exit(0)


def skip(hook: str = '', tool: str = '') -> None:
    """Exit 0 without an 'allowed' record. Use this when the hook doesn't
    apply to the current call at all (wrong tool, wrong subcommand)."""
    if hook:
        log_metric(hook, tool, 'skipped')
    sys.exit(0)


def _first_line(text: str) -> str:
    """First non-empty line, truncated to 160 chars — for metric reasons."""
    for line in text.splitlines():
        stripped = line.strip()
        if stripped:
            return stripped[:160]
    return ''
