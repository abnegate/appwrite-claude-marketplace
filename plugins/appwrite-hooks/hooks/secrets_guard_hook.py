#!/usr/bin/env python3
"""Block Edit/Write/MultiEdit on secret-bearing files.

The global rules say never commit `.env` or credential files. This hook
extends that beyond git commits to the edit layer: it blocks Claude from
creating or modifying files that look like secrets, full stop. If the
secret file doesn't get written in the first place, it can't leak into
a commit.

Path patterns blocked:
  - `.env` at any level (but NOT `.env.example` / `.env.sample`)
  - `credentials*`, `secrets*`
  - `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`
  - `id_rsa`, `id_ed25519`, `id_ecdsa`, `id_dsa`
  - `*.kubeconfig`, `kubeconfig`
  - `*.token`

Also scans WRITE CONTENT for obvious secret patterns (AWS, OpenAI, GitHub
PAT, private key blocks). Path-based wins are cheaper and cover most
cases; content scanning is a backstop.

Escape hatch: `APPWRITE_HOOKS_ALLOW_SECRETS=1` for the one command.
"""

import os
import re
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import allow, block, read_tool_input, skip

HOOK = 'secrets_guard'

PROTECTED_TOOLS = ('Edit', 'Write', 'MultiEdit')

SECRET_PATH_PATTERNS = (
    re.compile(r'(^|/)\.env(\.[a-zA-Z0-9_-]+)?$'),
    re.compile(r'(^|/)credentials(\.[a-z]+)?$', re.IGNORECASE),
    re.compile(r'(^|/)secrets?(\.[a-z]+)?$', re.IGNORECASE),
    re.compile(r'\.(pem|key|p12|pfx|jks|keystore|asc)$', re.IGNORECASE),
    re.compile(r'(^|/)id_(rsa|ed25519|ecdsa|dsa)$'),
    re.compile(r'(^|/)kubeconfig(\..+)?$', re.IGNORECASE),
    re.compile(r'\.token$', re.IGNORECASE),
    re.compile(r'(^|/)\.netrc$'),
    re.compile(r'(^|/)\.pgpass$'),
    re.compile(r'(^|/)\.aws/credentials$'),
)

# These look like secret files but aren't — sample/example/template files
# are exactly what you want to commit so people can see the shape.
PATH_ALLOWLIST_PATTERNS = (
    re.compile(r'\.(example|sample|template|dist|tpl)(\.[a-z]+)?$'),
    re.compile(r'\.example$'),
    re.compile(r'(^|/)example[-_]'),
    re.compile(r'(^|/)fixtures?/'),
    re.compile(r'(^|/)test(s|_data)?/'),
)

SECRET_CONTENT_PATTERNS = (
    (re.compile(r'AKIA[0-9A-Z]{16}'), 'AWS access key id'),
    (re.compile(r'aws_secret_access_key\s*=\s*[A-Za-z0-9/+=]{40}'), 'AWS secret access key'),
    (re.compile(r'sk-[A-Za-z0-9]{20,}'), 'OpenAI-style API key'),
    (re.compile(r'ghp_[A-Za-z0-9]{36}'), 'GitHub personal access token'),
    (re.compile(r'github_pat_[A-Za-z0-9_]{82}'), 'GitHub fine-grained PAT'),
    (re.compile(r'xoxb-[0-9]+-[0-9]+-[A-Za-z0-9]+'), 'Slack bot token'),
    (re.compile(r'-----BEGIN (RSA |EC |OPENSSH |DSA |PGP )?PRIVATE KEY-----'), 'private key block'),
    (re.compile(r'mongodb(\+srv)?://[^\s:]+:[^\s@]+@'), 'MongoDB connection string with credentials'),
    (re.compile(r'postgres(ql)?://[^\s:]+:[^\s@]+@'), 'Postgres connection string with credentials'),
)


def path_is_secret(path: str) -> bool:
    for pattern in PATH_ALLOWLIST_PATTERNS:
        if pattern.search(path):
            return False
    return any(pattern.search(path) for pattern in SECRET_PATH_PATTERNS)


def content_contains_secret(content: str) -> str:
    """Return the first matched secret label, or empty string."""
    if not content:
        return ''
    for pattern, label in SECRET_CONTENT_PATTERNS:
        if pattern.search(content):
            return label
    return ''


def extract_content(tool_name: str, tool_input: dict) -> str:
    if tool_name == 'Write':
        return tool_input.get('content', '') or ''
    if tool_name == 'Edit':
        return tool_input.get('new_string', '') or ''
    if tool_name == 'MultiEdit':
        edits = tool_input.get('edits', []) or []
        return '\n'.join(edit.get('new_string', '') or '' for edit in edits)
    return ''


def main() -> None:
    tool_name, tool_input = read_tool_input()
    if tool_name not in PROTECTED_TOOLS:
        skip(HOOK, tool_name)
        return

    file_path = tool_input.get('file_path', '') or ''
    if not file_path:
        skip(HOOK, tool_name)
        return

    if os.environ.get('APPWRITE_HOOKS_ALLOW_SECRETS') == '1':
        allow(HOOK, tool_name, 'opt-out-allow-secrets')
        return

    if path_is_secret(file_path):
        block(
            HOOK,
            tool_name,
            f'BLOCKED: refusing to {tool_name} a secret-bearing file.\n\n'
            f'  Path: {file_path}\n\n'
            f'This path matches a pattern reserved for credentials, keys, or '
            f'private material. The global rules forbid committing such files, '
            f'and the safest way to enforce that is to never write them in the '
            f'first place.\n\n'
            f'If you meant to edit a non-secret file with a similar name '
            f'(e.g. `.env.example`), rename the file.\n\n'
            f'If the user has explicitly authorized this edit, set '
            f'`APPWRITE_HOOKS_ALLOW_SECRETS=1` for the command.',
            reason=f'secret path: {Path(file_path).name}',
        )

    content = extract_content(tool_name, tool_input)
    leak = content_contains_secret(content)
    if leak:
        block(
            HOOK,
            tool_name,
            f'BLOCKED: the content being written contains what looks like a '
            f'{leak}.\n\n'
            f'  Path: {file_path}\n\n'
            f'If this is a test fixture or deliberate rotation of an expired '
            f'key, move it into a test/fixtures directory (which is '
            f'allowlisted) or set `APPWRITE_HOOKS_ALLOW_SECRETS=1` for the '
            f'command.',
            reason=f'content contains {leak}',
        )

    allow(HOOK, tool_name)


if __name__ == '__main__':
    main()
