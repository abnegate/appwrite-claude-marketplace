#!/usr/bin/env python3
"""Generate skills/INDEX.md from the 50 utopia-*-expert SKILL.md files.

Walks `plugins/utopia-experts/skills/`, parses each SKILL.md's YAML
frontmatter for `name` and `description`, assigns a category from a
hard-coded mapping (matching the groupings in the plugin README), and
emits a single Markdown table at `skills/INDEX.md`.

Run this whenever skills are added, removed, or renamed. The router
agent reads INDEX.md as its first action to pick relevant skills, so
keeping it in sync matters.

Usage:
    python3 plugins/utopia-experts/scripts/generate_index.py

The script discovers its own plugin root via __file__ so it can be run
from anywhere.
"""

import re
import sys
from pathlib import Path

# Category mapping — same groupings used in the plugin README and
# originally driven by the 10 research batches. Skills not listed here
# fall through to "other" (which should never trigger if the 50-skill
# set is stable).
CATEGORIES = {
    'framework': [
        'utopia-http-expert',
        'utopia-di-expert',
        'utopia-servers-expert',
        'utopia-platform-expert',
        'utopia-config-expert',
    ],
    'data': [
        'utopia-database-expert',
        'utopia-mongo-expert',
        'utopia-query-expert',
        'utopia-pools-expert',
        'utopia-dsn-expert',
    ],
    'storage-io': [
        'utopia-storage-expert',
        'utopia-cache-expert',
        'utopia-fetch-expert',
        'utopia-compression-expert',
        'utopia-migration-expert',
    ],
    'auth-security': [
        'utopia-auth-expert',
        'utopia-jwt-expert',
        'utopia-abuse-expert',
        'utopia-waf-expert',
        'utopia-validators-expert',
    ],
    'runtime': [
        'utopia-cli-expert',
        'utopia-system-expert',
        'utopia-orchestration-expert',
        'utopia-preloader-expert',
        'utopia-proxy-expert',
    ],
    'observability': [
        'utopia-logger-expert',
        'utopia-telemetry-expert',
        'utopia-audit-expert',
        'utopia-analytics-expert',
        'utopia-span-expert',
    ],
    'messaging-async': [
        'utopia-messaging-expert',
        'utopia-queue-expert',
        'utopia-websocket-expert',
        'utopia-async-expert',
        'utopia-emails-expert',
    ],
    'domain': [
        'utopia-pay-expert',
        'utopia-vcs-expert',
        'utopia-domains-expert',
        'utopia-dns-expert',
        'utopia-locale-expert',
    ],
    'utilities': [
        'utopia-ab-expert',
        'utopia-registry-expert',
        'utopia-detector-expert',
        'utopia-image-expert',
        'utopia-agents-expert',
    ],
    'misc': [
        'utopia-console-expert',
        'utopia-cloudevents-expert',
        'utopia-clickhouse-expert',
        'utopia-balancer-expert',
        'utopia-usage-expert',
    ],
}

FRONTMATTER = re.compile(r'^---\n(.*?)\n---', re.DOTALL)
YAML_FIELD = re.compile(r'^([\w-]+):\s*(.+?)$', re.MULTILINE)


def parse_frontmatter(content: str) -> dict[str, str]:
    match = FRONTMATTER.match(content)
    if not match:
        return {}
    fields: dict[str, str] = {}
    for line in match.group(1).splitlines():
        field = YAML_FIELD.match(line)
        if field:
            fields[field.group(1)] = field.group(2).strip()
    return fields


def lookup_category(skill_name: str) -> str:
    for category, skills in CATEGORIES.items():
        if skill_name in skills:
            return category
    return 'other'


def main() -> int:
    plugin_root = Path(__file__).parent.parent.resolve()
    skills_dir = plugin_root / 'skills'
    if not skills_dir.is_dir():
        print(f'error: {skills_dir} not found', file=sys.stderr)
        return 1

    entries: list[tuple[str, str, str]] = []
    for skill_md in sorted(skills_dir.glob('*/SKILL.md')):
        fields = parse_frontmatter(skill_md.read_text())
        name = fields.get('name', skill_md.parent.name)
        description = fields.get('description', '')
        category = lookup_category(name)
        entries.append((category, name, description))

    by_category: dict[str, list[tuple[str, str]]] = {}
    for category, name, description in entries:
        by_category.setdefault(category, []).append((name, description))

    output: list[str] = [
        '# Utopia Experts — Skill Index',
        '',
        f'Auto-generated index of the {len(entries)} `utopia-*-expert` skills in this plugin.',
        'The `utopia-router` agent reads this file first to decide which 1-3 skills to load',
        'for a given question. Regenerate with `scripts/generate_index.py` after any skill change.',
        '',
        '## How to use',
        '',
        'For surgical reference on one library, load the matching skill directly.',
        'For cross-cutting questions, dispatch to the `utopia-router` agent which will',
        'read this index, pick the most relevant skills, and return a synthesised answer.',
        '',
    ]

    category_order = list(CATEGORIES.keys()) + ['other']
    category_titles = {
        'framework': 'Framework core',
        'data': 'Data layer',
        'storage-io': 'Storage & I/O',
        'auth-security': 'Auth & security',
        'runtime': 'Runtime & system',
        'observability': 'Observability',
        'messaging-async': 'Messaging & async',
        'domain': 'Domain logic',
        'utilities': 'Utilities',
        'misc': 'Misc',
        'other': 'Other',
    }

    for category in category_order:
        if category not in by_category:
            continue
        output.append(f'## {category_titles[category]}')
        output.append('')
        output.append('| Skill | Description |')
        output.append('|---|---|')
        for name, description in sorted(by_category[category]):
            escaped = description.replace('|', '\\|')
            output.append(f'| `{name}` | {escaped} |')
        output.append('')

    output.append('## Composition notes for the router')
    output.append('')
    output.append('Some questions naturally span multiple skills. Known pairings:')
    output.append('')
    output.append('- **Observability pipeline** — `utopia-span-expert` +'
                  ' `utopia-logger-expert` + `utopia-telemetry-expert` +'
                  ' `utopia-audit-expert` + `utopia-analytics-expert`')
    output.append('- **Swoole pool stack** — `utopia-pools-expert` +'
                  ' `utopia-database-expert` + `utopia-cache-expert` +'
                  ' `utopia-mongo-expert`')
    output.append('- **SDK regen cascade** — `utopia-http-expert` +'
                  ' `utopia-validators-expert` + `utopia-platform-expert`')
    output.append('- **Custom-domain onboarding** — `utopia-domains-expert` +'
                  ' `utopia-dns-expert` + `utopia-vcs-expert`')
    output.append('- **Ingestion pipeline** — `utopia-cloudevents-expert` +'
                  ' `utopia-clickhouse-expert` + `utopia-usage-expert`')
    output.append('- **Rate limiting** — `utopia-abuse-expert` +'
                  ' `utopia-waf-expert` + `utopia-cache-expert`')
    output.append('- **Messaging worker** — `utopia-messaging-expert` +'
                  ' `utopia-queue-expert` + `utopia-async-expert`')
    output.append('')
    output.append('When a question matches a pairing, the router should load all'
                  ' relevant skills rather than picking just one.')
    output.append('')

    index_path = skills_dir / 'INDEX.md'
    index_path.write_text('\n'.join(output), encoding='utf-8')
    print(f'wrote {index_path} ({len(entries)} skills across {len(by_category)} categories)')
    return 0


if __name__ == '__main__':
    sys.exit(main())
