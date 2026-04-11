"""Shared helpers for appwrite-hooks git-commit guards.

All hooks are wired under PreToolUse:Bash, so every one of them sees every
shell invocation. These helpers do the cheap work of identifying whether the
command is a `git commit` that the hook should care about, and extracting the
message and flags from the command line.

Hook protocol reminder:
  - Read JSON from stdin with tool_name / tool_input
  - Exit 0 to allow
  - Exit 2 with stderr text to block (stderr is surfaced to Claude)
"""

import json
import re
import shlex
import sys
from typing import Optional


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
    if 'git commit' not in command:
        return None

    # Split on command separators (&&, ;, |) and look at each segment for a
    # git commit invocation. shlex handles quoted strings correctly.
    segments = re.split(r'&&|\|\||;|\|', command)
    for segment in segments:
        segment = segment.strip()
        if not segment.startswith('git') and 'git commit' not in segment:
            continue
        try:
            argv = shlex.split(segment, posix=True)
        except ValueError:
            # shlex chokes on the HEREDOC form. Fall back to a loose match —
            # capture the -m argument via regex so we can still inspect it.
            return _loose_parse(segment)
        if len(argv) >= 2 and argv[0] == 'git' and argv[1] == 'commit':
            return argv
    return None


def _loose_parse(segment: str) -> list[str]:
    """Fallback parser for HEREDOC-style commits that confuse shlex."""
    argv = ['git', 'commit']
    # Flags without values
    for flag in ('--no-verify', '--amend', '--force', '-n'):
        if re.search(rf'(?<!\S){re.escape(flag)}(?!\S)', segment):
            argv.append(flag)
    # -m "..." — grab the quoted payload. For HEREDOC we grab the inner text.
    heredoc = re.search(r"<<\s*'?EOF'?\s*\n(.*?)\nEOF", segment, re.DOTALL)
    if heredoc:
        argv.extend(['-m', heredoc.group(1).strip()])
        return argv
    simple = re.search(r'-m\s+"([^"]*)"', segment)
    if simple:
        argv.extend(['-m', simple.group(1)])
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


def block(message: str) -> None:
    """Emit stderr message and exit 2 — this is how PreToolUse hooks block."""
    print(message, file=sys.stderr)
    sys.exit(2)


def allow() -> None:
    sys.exit(0)
