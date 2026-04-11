#!/usr/bin/env python3
"""Validate frontmatter on every SKILL.md and command .md in the marketplace.

Walks `plugins/*/skills/*/SKILL.md` and `plugins/*/commands/*.md` and asserts:

  1. File has valid YAML frontmatter (--- block at top)
  2. `name:` field is present
  3. For SKILL.md: `name:` matches the parent directory name
  4. `description:` field is present and <= 500 chars
  5. No two skills share the same name (across all plugins)
  6. No two commands share the same name (across all plugins)

Also validates the top-level marketplace.json and each plugin.json parses as
JSON and has the required fields.

Exit code 0 on success, 1 on any failure with a report. Designed to run in
CI on every PR.
"""

import json
import re
import sys
from pathlib import Path

MAX_DESCRIPTION_CHARS = 500

FRONTMATTER_RE = re.compile(r'^---\n(.*?)\n---', re.DOTALL)
YAML_FIELD_RE = re.compile(r'^(\w+):\s*(.*?)$', re.MULTILINE)


def parse_frontmatter(content: str) -> dict[str, str]:
    """Parse the top --- block into a dict. Returns {} on no frontmatter."""
    match = FRONTMATTER_RE.match(content)
    if not match:
        return {}
    fields: dict[str, str] = {}
    for line in match.group(1).splitlines():
        field = YAML_FIELD_RE.match(line)
        if field:
            fields[field.group(1)] = field.group(2).strip()
    return fields


def validate_skill(path: Path, errors: list[str], seen_names: dict[str, Path]) -> None:
    content = path.read_text()
    if not content.startswith('---'):
        errors.append(f'{path}: missing frontmatter (no leading ---)')
        return

    fields = parse_frontmatter(content)
    if not fields:
        errors.append(f'{path}: frontmatter block not closed (no trailing ---)')
        return

    name = fields.get('name', '')
    description = fields.get('description', '')

    if not name:
        errors.append(f'{path}: missing `name:` field')
    elif name != path.parent.name:
        errors.append(
            f'{path}: name mismatch — frontmatter `name: {name}` '
            f'does not match directory `{path.parent.name}`'
        )
    elif name in seen_names:
        errors.append(
            f'{path}: duplicate skill name `{name}` — also declared at '
            f'{seen_names[name]}'
        )
    else:
        seen_names[name] = path

    if not description:
        errors.append(f'{path}: missing `description:` field')
    elif len(description) > MAX_DESCRIPTION_CHARS:
        errors.append(
            f'{path}: description is {len(description)} chars '
            f'(max {MAX_DESCRIPTION_CHARS})'
        )


def validate_command(path: Path, errors: list[str], seen_names: dict[str, Path]) -> None:
    content = path.read_text()
    if not content.startswith('---'):
        errors.append(f'{path}: missing frontmatter')
        return

    fields = parse_frontmatter(content)
    if not fields:
        errors.append(f'{path}: frontmatter block not closed')
        return

    description = fields.get('description', '')
    if not description:
        errors.append(f'{path}: commands must have a `description:` field')
    elif len(description) > MAX_DESCRIPTION_CHARS:
        errors.append(
            f'{path}: description is {len(description)} chars '
            f'(max {MAX_DESCRIPTION_CHARS})'
        )

    # Commands don't have a `name:` field — their name comes from the
    # filename. Check for duplicates across all commands.
    stem = path.stem
    if stem in seen_names:
        errors.append(
            f'{path}: duplicate command name `{stem}` — also declared at '
            f'{seen_names[stem]}'
        )
    else:
        seen_names[stem] = path


def validate_agent(path: Path, errors: list[str], seen_names: dict[str, Path]) -> None:
    content = path.read_text()
    if not content.startswith('---'):
        errors.append(f'{path}: missing frontmatter')
        return

    fields = parse_frontmatter(content)
    name = fields.get('name', '')
    if not name:
        errors.append(f'{path}: agents must have a `name:` field')
    elif name in seen_names:
        errors.append(
            f'{path}: duplicate agent name `{name}` — also declared at '
            f'{seen_names[name]}'
        )
    else:
        seen_names[name] = path

    if not fields.get('description'):
        errors.append(f'{path}: agents must have a `description:` field')


def validate_json_manifest(path: Path, errors: list[str]) -> None:
    try:
        data = json.loads(path.read_text())
    except json.JSONDecodeError as exception:
        errors.append(f'{path}: invalid JSON ({exception})')
        return
    if not isinstance(data, dict):
        errors.append(f'{path}: top-level must be an object')
        return
    if 'name' not in data:
        errors.append(f'{path}: missing `name` field')
    if 'description' not in data:
        errors.append(f'{path}: missing `description` field')


def main() -> int:
    root = Path(__file__).parent.parent.resolve()
    errors: list[str] = []

    seen_skill_names: dict[str, Path] = {}
    seen_command_names: dict[str, Path] = {}
    seen_agent_names: dict[str, Path] = {}

    # Skills: plugins/*/skills/*/SKILL.md
    for path in sorted(root.glob('plugins/*/skills/*/SKILL.md')):
        validate_skill(path, errors, seen_skill_names)

    # Commands: plugins/*/commands/*.md
    for path in sorted(root.glob('plugins/*/commands/*.md')):
        validate_command(path, errors, seen_command_names)

    # Agents: plugins/*/agents/*.md
    for path in sorted(root.glob('plugins/*/agents/*.md')):
        validate_agent(path, errors, seen_agent_names)

    # JSON manifests
    for manifest in (root / '.claude-plugin' / 'marketplace.json',):
        if manifest.exists():
            validate_json_manifest(manifest, errors)
    for plugin_json in sorted(root.glob('plugins/*/.claude-plugin/plugin.json')):
        validate_json_manifest(plugin_json, errors)
    for hooks_json in sorted(root.glob('plugins/*/hooks/hooks.json')):
        try:
            json.loads(hooks_json.read_text())
        except json.JSONDecodeError as exception:
            errors.append(f'{hooks_json}: invalid JSON ({exception})')

    skill_count = len(seen_skill_names)
    command_count = len(seen_command_names)
    agent_count = len(seen_agent_names)

    if errors:
        for error in errors:
            print(f'  {error}', file=sys.stderr)
        print(
            f'\nFAIL: {len(errors)} errors across {skill_count} skills, '
            f'{command_count} commands, {agent_count} agents',
            file=sys.stderr,
        )
        return 1

    print(
        f'OK: {skill_count} skills, {command_count} commands, '
        f'{agent_count} agents — all valid'
    )
    return 0


if __name__ == '__main__':
    sys.exit(main())
