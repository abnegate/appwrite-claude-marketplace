#!/usr/bin/env python3
"""Run format + lint before allowing a commit.

Detects the ecosystem from staged files and runs the matching tool:

  *.php                     -> composer lint          (Pint / PSR-12)
  *.kt / build.gradle(.kts) -> ktlint / spotlessCheck
  *.rs / Cargo.toml         -> cargo fmt --check && cargo clippy -D warnings
  *.ts / *.tsx / *.js       -> npx prettier --check
  *.py                      -> ruff check

The hook runs each tool inside the repo root (detected from `cwd` then
walked up to `.git`). If the tool binary isn't on PATH, the hook logs a
warning and allows the commit — this hook's job is to catch regressions
when the toolchain is set up, not to block unrelated projects.

Escape hatch: `APPWRITE_HOOKS_SKIP_LINT=1` bypasses everything.
"""

import os
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from _shared import allow, block, extract_git_commit, read_tool_input, skip

HOOK = 'format_lint'


def staged_files(cwd: str) -> list[str]:
    try:
        result = subprocess.run(
            ['git', 'diff', '--cached', '--name-only'],
            cwd=cwd or None,
            capture_output=True,
            text=True,
            timeout=5,
        )
    except (subprocess.SubprocessError, FileNotFoundError):
        return []
    if result.returncode != 0:
        return []
    return [line.strip() for line in result.stdout.splitlines() if line.strip()]


def find_repo_root(start: str) -> Path:
    current = Path(start or '.').resolve()
    for parent in (current, *current.parents):
        if (parent / '.git').exists():
            return parent
    return current


def any_match(files: list[str], suffixes: tuple[str, ...]) -> bool:
    return any(f.endswith(suffixes) for f in files)


def run(cmd: list[str], cwd: Path, label: str) -> tuple[bool, str]:
    """Execute a lint command. Returns (ok, output)."""
    if shutil.which(cmd[0]) is None:
        return True, f'{label}: skipped ({cmd[0]} not on PATH)'
    try:
        result = subprocess.run(
            cmd,
            cwd=cwd,
            capture_output=True,
            text=True,
            timeout=120,
        )
    except subprocess.TimeoutExpired:
        return False, f'{label}: timed out after 120s'
    except (subprocess.SubprocessError, OSError) as exception:
        return True, f'{label}: skipped ({exception})'

    if result.returncode == 0:
        return True, f'{label}: ok'
    return False, (
        f'{label}: FAILED\n'
        f'--- stdout ---\n{result.stdout.strip()}\n'
        f'--- stderr ---\n{result.stderr.strip()}'
    )


def main() -> None:
    if os.environ.get('APPWRITE_HOOKS_SKIP_LINT') == '1':
        skip(HOOK, 'Bash')

    tool_name, tool_input = read_tool_input()
    if tool_name != 'Bash':
        skip(HOOK, tool_name)

    argv = extract_git_commit(tool_input.get('command', ''))
    if argv is None:
        skip(HOOK, tool_name)

    files = staged_files(tool_input.get('cwd', ''))
    if not files:
        skip(HOOK, tool_name)

    repo_root = find_repo_root(tool_input.get('cwd', ''))
    results: list[tuple[bool, str]] = []

    if any_match(files, ('.php',)) and (repo_root / 'composer.json').exists():
        results.append(run(['composer', 'lint'], repo_root, 'composer lint'))

    if any_match(files, ('.kt', '.kts')):
        if (repo_root / 'gradlew').exists():
            results.append(run(['./gradlew', 'ktlintCheck'], repo_root, 'ktlintCheck'))
        elif shutil.which('ktlint'):
            results.append(run(['ktlint', '--format'], repo_root, 'ktlint'))

    if any_match(files, ('.rs',)) and (repo_root / 'Cargo.toml').exists():
        results.append(run(['cargo', 'fmt', '--check'], repo_root, 'cargo fmt'))
        results.append(run(
            ['cargo', 'clippy', '--all-targets', '--', '-D', 'warnings'],
            repo_root,
            'cargo clippy',
        ))

    if any_match(files, ('.ts', '.tsx', '.js', '.jsx', '.mjs', '.cjs')):
        if (repo_root / 'package.json').exists() and shutil.which('npx'):
            results.append(run(
                ['npx', '--no-install', 'prettier', '--check', '.'],
                repo_root,
                'prettier',
            ))

    if any_match(files, ('.py',)):
        if shutil.which('ruff'):
            results.append(run(['ruff', 'check', '.'], repo_root, 'ruff'))

    failures = [output for ok, output in results if not ok]
    if failures:
        block(
            HOOK,
            tool_name,
            'BLOCKED: format/lint checks failed. Fix and retry.\n\n'
            + '\n\n'.join(failures)
            + '\n\n(Override with APPWRITE_HOOKS_SKIP_LINT=1 if strictly necessary.)',
            reason='lint failed',
        )

    allow(HOOK, tool_name)


if __name__ == '__main__':
    main()
